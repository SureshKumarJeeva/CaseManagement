<?php

namespace Drupal\associatedps_email_template\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Sends mail through Microsoft 365 via the Graph API (app-only).
 *
 * Uses the OAuth2 client-credentials flow (no interactive login) to send as one
 * fixed company mailbox, with saveToSentItems=true so a copy lands in that
 * mailbox's Sent Items. Replaces Drupal's mailManager/admin-SMTP path for the
 * automated client-update emails.
 *
 * Config is env-driven (matching XeroClient / DocuSignClient):
 *   MS_GRAPH_TENANT_ID, MS_GRAPH_CLIENT_ID, MS_GRAPH_CLIENT_SECRET,
 *   MS_GRAPH_FROM_MAILBOX.
 *
 * Required Entra ID setup (see D:\wamp64\www\m365-graph-mail-setup.md):
 *   - App registration with the *Application* permission Mail.Send + admin
 *     consent.
 *   - An Exchange Application Access Policy restricting the app to the single
 *     sender mailbox.
 */
class Microsoft365MailService {

  /**
   * Cache id for the app-only access token.
   */
  protected const TOKEN_CID = 'associatedps_email_template:ms_graph_token';

  protected ClientInterface $httpClient;
  protected CacheBackendInterface $cache;
  protected LoggerChannelInterface $logger;

  /** Config (env-driven). */
  protected string $tenantId;
  protected string $clientId;
  protected string $clientSecret;
  protected string $fromMailbox;

  public function __construct(
    ClientInterface $http_client,
    CacheBackendInterface $cache,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->httpClient = $http_client;
    $this->cache = $cache;
    $this->logger = $logger_factory->get('associatedps_email_template');

    $this->tenantId = getenv('MS_GRAPH_TENANT_ID') ?: '';
    $this->clientId = getenv('MS_GRAPH_CLIENT_ID') ?: '';
    $this->clientSecret = getenv('MS_GRAPH_CLIENT_SECRET') ?: '';
    $this->fromMailbox = getenv('MS_GRAPH_FROM_MAILBOX') ?: '';
  }

  /**
   * Whether all required credentials are present.
   */
  public function isConfigured(): bool {
    return $this->tenantId !== ''
      && $this->clientId !== ''
      && $this->clientSecret !== ''
      && $this->fromMailbox !== '';
  }

  /**
   * The configured sender mailbox address.
   */
  public function getFromMailbox(): string {
    return $this->fromMailbox;
  }

  /**
   * Get a valid app-only access token, minting + caching a fresh one if needed.
   *
   * @return string
   *   The bearer token, or '' on failure (the reason is logged).
   */
  public function getAccessToken(): string {
    $cached = $this->cache->get(self::TOKEN_CID);
    if ($cached && !empty($cached->data)) {
      return $cached->data;
    }

    if (!$this->isConfigured()) {
      $this->logger->error('Microsoft365MailService: not configured (missing MS_GRAPH_* env var(s)). Cannot mint token.');
      return '';
    }

    $url = 'https://login.microsoftonline.com/' . rawurlencode($this->tenantId) . '/oauth2/v2.0/token';
    try {
      $response = $this->httpClient->post($url, [
        'headers' => [
          'Content-Type' => 'application/x-www-form-urlencoded',
          'Accept' => 'application/json',
        ],
        'form_params' => [
          'client_id' => $this->clientId,
          'client_secret' => $this->clientSecret,
          'scope' => 'https://graph.microsoft.com/.default',
          'grant_type' => 'client_credentials',
        ],
      ]);
      $data = json_decode((string) $response->getBody(), TRUE) ?: [];
      if (empty($data['access_token'])) {
        $this->logger->error('Microsoft365MailService: token endpoint returned no access_token: @body', ['@body' => (string) $response->getBody()]);
        return '';
      }
      $token = $data['access_token'];
      // Cache until 60s before expiry (default 3600s if not provided).
      $expires_in = (int) ($data['expires_in'] ?? 3600);
      $this->cache->set(self::TOKEN_CID, $token, time() + max(60, $expires_in - 60));
      return $token;
    }
    catch (RequestException $e) {
      $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      $this->logger->error('Microsoft365MailService: token request failed: @msg | body: @body', [
        '@msg' => $e->getMessage(),
        '@body' => $body,
      ]);
      return '';
    }
    catch (\Throwable $e) {
      $this->logger->error('Microsoft365MailService: token request error: @msg', ['@msg' => $e->getMessage()]);
      return '';
    }
  }

  /**
   * Send an HTML email through Graph as the configured mailbox.
   *
   * The message is also filed in the mailbox's Sent Items (saveToSentItems).
   *
   * @param string $to
   *   Recipient email address.
   * @param string $subject
   *   Email subject.
   * @param string $html_body
   *   HTML body.
   * @param array $options
   *   Optional: 'cc' (array of addresses), 'bcc' (array of addresses),
   *   'reply_to' (single address), 'attachments' (array of
   *   ['name' => string, 'contentType' => string, 'content' => raw bytes]).
   *
   * @return array
   *   ['success' => bool, 'error' => string|null].
   */
  public function sendMail(string $to, string $subject, string $html_body, array $options = []): array {
    if (!$this->isConfigured()) {
      $msg = 'Microsoft365MailService: not configured (missing MS_GRAPH_* env var(s)).';
      $this->logger->error($msg);
      return ['success' => FALSE, 'error' => $msg];
    }
    if (trim($to) === '') {
      $msg = 'Microsoft365MailService: empty recipient address; not sending.';
      $this->logger->warning($msg);
      return ['success' => FALSE, 'error' => $msg];
    }

    $token = $this->getAccessToken();
    if ($token === '') {
      return ['success' => FALSE, 'error' => 'Could not obtain Microsoft Graph access token (see log).'];
    }

    $message = [
      'subject' => $subject,
      'body' => [
        'contentType' => 'HTML',
        'content' => $html_body,
      ],
      'toRecipients' => [
        ['emailAddress' => ['address' => $to]],
      ],
    ];
    if (!empty($options['cc'])) {
      $message['ccRecipients'] = array_map(fn ($addr) => ['emailAddress' => ['address' => $addr]], (array) $options['cc']);
    }
    if (!empty($options['bcc'])) {
      $message['bccRecipients'] = array_map(fn ($addr) => ['emailAddress' => ['address' => $addr]], (array) $options['bcc']);
    }
    if (!empty($options['reply_to'])) {
      $message['replyTo'] = [['emailAddress' => ['address' => $options['reply_to']]]];
    }
    // File attachments — base64 inline (#microsoft.graph.fileAttachment). The
    // sendMail action carries attachments inline, so total request size must
    // stay within Graph's limit (~4 MB); the caller is responsible for capping.
    if (!empty($options['attachments'])) {
      $graph_attachments = [];
      foreach ((array) $options['attachments'] as $att) {
        if (empty($att['name']) || !isset($att['content'])) {
          continue;
        }
        $graph_attachments[] = [
          '@odata.type' => '#microsoft.graph.fileAttachment',
          'name' => $att['name'],
          'contentType' => $att['contentType'] ?? 'application/octet-stream',
          'contentBytes' => base64_encode((string) $att['content']),
        ];
      }
      if ($graph_attachments) {
        $message['attachments'] = $graph_attachments;
      }
    }

    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($this->fromMailbox) . '/sendMail';
    try {
      $response = $this->httpClient->post($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
        ],
        'json' => [
          'message' => $message,
          'saveToSentItems' => TRUE,
        ],
      ]);
      // Graph sendMail returns 202 Accepted with an empty body on success.
      $status = $response->getStatusCode();
      if ($status === 202 || $status === 200) {
        $this->logger->notice('Microsoft365MailService: sent "@subject" to @to from @from.', [
          '@subject' => $subject,
          '@to' => $to,
          '@from' => $this->fromMailbox,
        ]);
        return ['success' => TRUE, 'error' => NULL];
      }
      $this->logger->error('Microsoft365MailService: unexpected status @status sending to @to: @body', [
        '@status' => $status,
        '@to' => $to,
        '@body' => (string) $response->getBody(),
      ]);
      return ['success' => FALSE, 'error' => 'Unexpected Graph response status ' . $status . '.'];
    }
    catch (RequestException $e) {
      $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      // A 401 most often means an expired/rotated secret or stale cached token;
      // drop the cache so the next attempt re-mints.
      if ($e->hasResponse() && $e->getResponse()->getStatusCode() === 401) {
        $this->cache->delete(self::TOKEN_CID);
      }
      $this->logger->error('Microsoft365MailService: sendMail failed for @to: @msg | body: @body', [
        '@to' => $to,
        '@msg' => $e->getMessage(),
        '@body' => $body,
      ]);
      return ['success' => FALSE, 'error' => 'Graph sendMail failed: ' . $e->getMessage()];
    }
    catch (\Throwable $e) {
      $this->logger->error('Microsoft365MailService: sendMail error for @to: @msg', [
        '@to' => $to,
        '@msg' => $e->getMessage(),
      ]);
      return ['success' => FALSE, 'error' => 'Graph sendMail error: ' . $e->getMessage()];
    }
  }

}
