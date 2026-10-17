<?php

namespace Drupal\associatedps_affidavit_template\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

/**
 * Talks to the Python docxtpl merge service.
 *
 * The service lives at services/aps-docgen/ and runs on this same box, bound
 * to 127.0.0.1. It takes a .docx template plus a Job's resolved token values
 * and returns the merged document.
 *
 * WHY OUR OWN SERVICE AND NOT DOCUSIGN: the tokens in these templates are
 * Jinja2 (`{{ Client.Mail }}`), which lets a template loop over rows with
 * `{%tr for %}` — dynamic tables that neither the DocuSign tab flow nor its
 * Document Generation trial could produce. See services/aps-docgen/README.md.
 *
 * ⚠ The token names are dotted and every parent is BOTH a scalar and a
 * namespace (`Client` is a company name AND the parent of `Client.Mail`).
 * That is handled Python-side; this class passes the pairs through untouched.
 */
class WordMergeClient {

  protected ClientInterface $httpClient;
  protected LoggerChannelInterface $logger;

  protected string $baseUrl;
  protected string $key;
  protected int $timeout;

  public function __construct(
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('word_docgen');

    $this->baseUrl = rtrim(getenv('APS_DOCGEN_URL') ?: 'http://127.0.0.1:8099', '/');
    $this->key = getenv('APS_DOCGEN_KEY') ?: '';
    // Merging is CPU work on a document that can run to hundreds of pages, but
    // a request that has not finished in this long is stuck rather than slow.
    $this->timeout = (int) (getenv('APS_DOCGEN_TIMEOUT') ?: 60);
  }

  /**
   * Whether the service is configured enough to attempt a call.
   */
  public function isConfigured(): bool {
    return $this->baseUrl !== '' && $this->key !== '';
  }

  /**
   * Merge a template with a Job's values.
   *
   * @param string $template_bytes
   *   The .docx template.
   * @param array $tokens
   *   Pairs as DocuSignTokenMapper::resolveTokensForJob() returns them:
   *   [['name' => 'Client.Mail', 'value' => '...'], ...]. Passed through
   *   unchanged — the Python side owns the namespace handling.
   * @param array $tables
   *   Optional structured rows keyed by parent token name, for `{%tr for %}`:
   *   ['Job_Fees' => [['Description' => 'Delivery Fee', 'Amount' => '35.00']]].
   *   ⚠ The flat $tokens CANNOT express these — resolveTokensForJob()
   *   flattens multi-value fields to comma-joined strings, so rows have to be
   *   built here in Drupal and sent separately.
   * @param array $lists
   *   Optional one-column lists keyed by the full dotted token name, as
   *   DocuSignTokenMapper::resolveListTokensForJob() returns them:
   *   ['Documents.Affidavit_Template' => ['Test Affidavit 5', 'QCAT - ...']].
   *   The service renders each item as its own paragraph, so a plain
   *   {{ Documents.Affidavit_Template }} lists one template per line without
   *   the author having to write a {%tr for %} loop.
   * @param string $filename
   *   Template filename, for the service's logs only.
   *
   * @return string
   *   The merged .docx bytes.
   *
   * @throws \RuntimeException
   *   With a message written to be shown to the staff member. The distinction
   *   that matters: a 422 means THEIR template is wrong, anything else means
   *   ours or the service's.
   */
  public function render(string $template_bytes, array $tokens, array $tables = [], array $lists = [], string $filename = 'template.docx'): string {
    if (!$this->isConfigured()) {
      throw new \RuntimeException('The document generation service is not configured (APS_DOCGEN_URL / APS_DOCGEN_KEY).');
    }
    if ($template_bytes === '') {
      throw new \RuntimeException('The template file is empty.');
    }

    // Dates are normalised on the way out, for every shape at once — see
    // localiseDates().
    $payload = ['tokens' => $this->localiseDates(array_values($tokens))];
    if (!empty($tables)) {
      $payload['tables'] = $this->localiseDates($tables);
    }
    if (!empty($lists)) {
      $payload['lists'] = $this->localiseDates($lists);
    }

    // Correlates a failure here with the service's own log line.
    $request_id = uniqid('aps-', TRUE);

    try {
      $response = $this->httpClient->request('POST', $this->baseUrl . '/render', [
        'headers' => [
          'X-APS-Key' => $this->key,
          'X-Request-Id' => $request_id,
        ],
        'multipart' => [
          [
            'name' => 'file',
            'contents' => $template_bytes,
            'filename' => $filename,
            'headers' => ['Content-Type' => 'application/octet-stream'],
          ],
          [
            'name' => 'data',
            'contents' => json_encode($payload, JSON_UNESCAPED_SLASHES),
          ],
        ],
        'timeout' => $this->timeout,
        // We read the status ourselves so a 422 can carry the author-facing
        // message from the service rather than becoming a generic exception.
        'http_errors' => FALSE,
      ]);
    }
    catch (ConnectException $e) {
      $this->logger->error('Merge service unreachable at @url [req=@req]: @msg', [
        '@url' => $this->baseUrl,
        '@req' => $request_id,
        '@msg' => $e->getMessage(),
      ]);
      throw new \RuntimeException('The document generation service is not running. Please contact support.');
    }
    catch (RequestException $e) {
      $this->logger->error('Merge request failed [req=@req]: @msg', [
        '@req' => $request_id,
        '@msg' => $e->getMessage(),
      ]);
      throw new \RuntimeException('The document could not be generated. Please try again.');
    }

    $status = $response->getStatusCode();
    $body = (string) $response->getBody();

    if ($status === 200) {
      if ($body === '') {
        $this->logger->error('Merge returned an empty document [req=@req].', ['@req' => $request_id]);
        throw new \RuntimeException('The document generation service returned an empty file.');
      }
      $this->logger->info('Merged @file [req=@req] in=@in out=@out tokens=@tokens', [
        '@file' => $filename,
        '@req' => $request_id,
        '@in' => strlen($template_bytes),
        '@out' => strlen($body),
        '@tokens' => count($tokens),
      ]);
      return $body;
    }

    $detail = $this->extractDetail($body);

    // 422 is the author's own template — a malformed placeholder, or a file
    // that is not really a .docx. Surface the service's message verbatim,
    // because it is written to tell them what to fix.
    if ($status === 422) {
      $this->logger->warning('Template rejected [req=@req]: @detail', [
        '@req' => $request_id,
        '@detail' => $detail,
      ]);
      throw new \RuntimeException($detail !== '' ? $detail : 'The template could not be merged.');
    }

    $this->logger->error('Merge service returned @status [req=@req]: @detail', [
      '@status' => $status,
      '@req' => $request_id,
      '@detail' => $detail,
    ]);

    if ($status === 401 || $status === 503) {
      throw new \RuntimeException('The document generation service rejected this request. Please contact support.');
    }

    throw new \RuntimeException('The document could not be generated (error ' . $status . ').');
  }

  /**
   * Check the service is up, for the config form's Test connection button.
   *
   * @return array
   *   ['ok' => bool, 'message' => string, 'data' => array].
   */
  public function health(): array {
    if ($this->baseUrl === '') {
      return ['ok' => FALSE, 'message' => 'No service URL configured.', 'data' => []];
    }

    try {
      $response = $this->httpClient->request('GET', $this->baseUrl . '/health', [
        'timeout' => 5,
        'http_errors' => FALSE,
      ]);
    }
    catch (\Throwable $e) {
      return ['ok' => FALSE, 'message' => 'Service unreachable: ' . $e->getMessage(), 'data' => []];
    }

    if ($response->getStatusCode() !== 200) {
      return [
        'ok' => FALSE,
        'message' => 'Service returned HTTP ' . $response->getStatusCode() . '.',
        'data' => [],
      ];
    }

    $data = json_decode((string) $response->getBody(), TRUE) ?: [];

    // Reachable but unusable is worth calling out separately — this is the
    // shape of a misdeploy, and it would otherwise only show as 503s later.
    if (empty($data['key_configured'])) {
      return [
        'ok' => FALSE,
        'message' => 'Service is running but has no APS_DOCGEN_KEY set; it will refuse every request.',
        'data' => $data,
      ];
    }

    return ['ok' => TRUE, 'message' => 'Service is running.', 'data' => $data];
  }

  /**
   * Date format for a value that carries a time.
   *
   * Lowercase am/pm ('a'); swap to 'A' for AM/PM. One constant, used for every
   * date reaching a merged affidavit.
   */
  protected const DATE_TIME_FORMAT = 'd/m/Y h:i a';

  /**
   * Date format for a date-only value.
   */
  protected const DATE_FORMAT = 'd/m/Y';

  /**
   * Drupal's own storage formats — the reason the pattern below can be trusted.
   *
   * A datetime field stores 'Y-m-d\TH:i:s' in UTC; a date-only field stores
   * 'Y-m-d' with no timezone at all. So the presence of the time part is not a
   * guess, it IS the datetime_type, read from the value itself.
   *
   * ⚠ That matters here more than it looks: field_deadline has already drifted
   * between datetime and date between environments once, invisibly in the UI.
   * Reading the shape of the value cannot drift with it.
   */
  protected const ISO_DATE = '/\b(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?\b/';

  /**
   * Rewrite every stored date in the payload as staff read it.
   *
   * TWO separate problems, both fixed here:
   *
   * 1. FORMAT. Values reach us as Drupal stores them — "2020-09-11T07:23:00" —
   *    which is not what belongs in an affidavit.
   *
   * 2. ⚠ TIMEZONE, and this one is a correctness bug, not presentation. A
   *    datetime field is stored in UTC. Attendance note 336/1 stores
   *    07:23 and Drupal displays "Fri, 11/09/2020 - 17:23" in
   *    Australia/Sydney — so the merged document was stating an attendance
   *    time TEN HOURS off, in a document that goes to a court. Date-only
   *    values are NOT shifted: they have no timezone, and converting one can
   *    move it to the previous day.
   *
   * WHY HERE AND NOT IN DocuSignTokenMapper: the mapper feeds DocuSign too,
   * where the tab values must keep matching what that flow expects. This class
   * is the only path to the Word merge service, so a change here cannot reach
   * anything else.
   *
   * The rewrite runs over whole strings rather than whole values because
   * cellValue() comma-joins a multi-value field into one cell, so a single
   * string can hold several dates.
   *
   * @param mixed $value
   *   Any part of the payload — arrays are walked, strings are rewritten.
   *
   * @return mixed
   *   The same structure with dates localised.
   */
  protected function localiseDates($value) {
    if (is_array($value)) {
      return array_map([$this, 'localiseDates'], $value);
    }
    if (!is_string($value) || $value === '') {
      return $value;
    }

    return preg_replace_callback(self::ISO_DATE, function (array $m): string {
      $hasTime = isset($m[4]) && $m[4] !== '';

      try {
        if (!$hasTime) {
          // No timezone in the stored value and none wanted: shifting a bare
          // date can land it on the day before.
          $date = new \DateTime($m[1] . '-' . $m[2] . '-' . $m[3], new \DateTimeZone('UTC'));
          return $date->format(self::DATE_FORMAT);
        }

        $date = new \DateTime(
          $m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $m[4] . ':' . $m[5] . ':' . ($m[6] ?? '00'),
          new \DateTimeZone('UTC')
        );
        // date_default_timezone_get() is what Drupal has already set for this
        // request — the site default, or the user's own if they have one — so
        // this matches the time the same staff member sees on the Job form.
        $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $date->format(self::DATE_TIME_FORMAT);
      }
      catch (\Exception $e) {
        // A value that looked like a date but is not one (an impossible month,
        // say) is left exactly as it was rather than dropped.
        return $m[0];
      }
    }, $value);
  }

  /**
   * Pull FastAPI's `detail` out of an error body.
   */
  protected function extractDetail(string $body): string {
    $decoded = json_decode($body, TRUE);
    if (is_array($decoded) && isset($decoded['detail']) && is_string($decoded['detail'])) {
      return $decoded['detail'];
    }
    return '';
  }

}
