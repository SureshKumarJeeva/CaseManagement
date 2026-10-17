<?php

namespace Drupal\associatedps_affidavit_template\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * App-only Microsoft Graph client for SharePoint document-library files.
 *
 * This is the transport for Affidavit template authoring: a .docx is pushed into
 * a SharePoint library owned by our tenant, the user edits it in Word for the
 * web, and we pull the edited bytes back to the server. Token merging afterwards
 * is unchanged (Python + docxtpl).
 *
 * WHY A SEPARATE SERVICE AND NOT Microsoft365MailService:
 * that service is the production path for every automated client email, and it
 * caches its app-only token under its own cid. Adding file scopes to it would
 * put the email path at risk for no benefit — the client-credentials token is
 * ".default", so both services get the same permissions regardless. They are
 * deliberately decoupled; only the cache key differs.
 *
 * WHY THE FILE LIVES IN *OUR* LIBRARY AND NOT THE USER'S ONEDRIVE:
 * if the user opened it from their own account the edited copy would land in
 * their personal drive and we would have no route back to it. Everything here
 * targets a site drive. /me/drive is not used and its absence is not an error.
 *
 * Config (env-driven, matching XeroClient / DocuSignClient / the mail service):
 *   MS_GRAPH_TENANT_ID, MS_GRAPH_CLIENT_ID, MS_GRAPH_CLIENT_SECRET  (required)
 *   MS_GRAPH_SITE_ID      optional, default 'root'
 *   MS_GRAPH_DRIVE_FOLDER optional, default 'AffidavitTemplates'
 *
 * REQUIRED ENTRA SETUP — this will 403 until it is done:
 *   App registration -> API permissions -> Microsoft Graph -> *Application* ->
 *   Sites.ReadWrite.All -> Grant admin consent.
 *   Sites.ReadWrite.All, NOT Files.ReadWrite.All: we target a site library.
 */
class GraphDriveClient {

  /**
   * Cache id for this service's app-only access token.
   *
   * Deliberately NOT shared with Microsoft365MailService — see the class doc.
   */
  protected const TOKEN_CID = 'associatedps_affidavit_template:graph_drive_token';

  protected const GRAPH = 'https://graph.microsoft.com/v1.0';

  protected ClientInterface $httpClient;
  protected CacheBackendInterface $cache;
  protected LoggerChannelInterface $logger;

  protected string $tenantId;
  protected string $clientId;
  protected string $clientSecret;
  protected string $siteId;
  protected string $folder;

  public function __construct(
    ClientInterface $http_client,
    CacheBackendInterface $cache,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->httpClient = $http_client;
    $this->cache = $cache;
    $this->logger = $logger_factory->get('associatedps_affidavit_template');

    $this->tenantId = getenv('MS_GRAPH_TENANT_ID') ?: '';
    $this->clientId = getenv('MS_GRAPH_CLIENT_ID') ?: '';
    $this->clientSecret = getenv('MS_GRAPH_CLIENT_SECRET') ?: '';
    $this->siteId = getenv('MS_GRAPH_SITE_ID') ?: 'root';
    $this->folder = trim(getenv('MS_GRAPH_DRIVE_FOLDER') ?: 'AffidavitTemplates', '/');
  }

  /**
   * Whether the three required credentials are present.
   */
  public function isConfigured(): bool {
    return $this->tenantId !== '' && $this->clientId !== '' && $this->clientSecret !== '';
  }

  /**
   * The library subfolder templates are written to.
   */
  public function getFolder(): string {
    return $this->folder;
  }

  /**
   * Drop the cached access token.
   *
   * MUST be called after an admin changes the app's API permissions. The
   * client-credentials token carries the permissions it had when it was minted,
   * so a token cached before consent keeps 403ing for its full hour even though
   * the grant has landed. That failure looks exactly like "consent didn't work"
   * and has cost real time on this project before.
   */
  public function resetToken(): void {
    $this->cache->delete(self::TOKEN_CID);
  }

  /**
   * Get a valid app-only access token, minting and caching one if needed.
   *
   * @return string
   *   The bearer token.
   *
   * @throws \RuntimeException
   *   If credentials are missing or the token endpoint rejects them.
   */
  public function getAccessToken(): string {
    $cached = $this->cache->get(self::TOKEN_CID);
    if ($cached && !empty($cached->data)) {
      return $cached->data;
    }

    if (!$this->isConfigured()) {
      throw new \RuntimeException('Microsoft Graph is not configured: set MS_GRAPH_TENANT_ID, MS_GRAPH_CLIENT_ID and MS_GRAPH_CLIENT_SECRET.');
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
    }
    catch (RequestException $e) {
      $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      $this->logger->error('GraphDriveClient: token request failed: @msg | body: @body', [
        '@msg' => $e->getMessage(),
        '@body' => $body,
      ]);
      throw new \RuntimeException('Could not obtain a Microsoft Graph token: ' . $e->getMessage());
    }

    if (empty($data['access_token'])) {
      throw new \RuntimeException('The Microsoft Graph token endpoint returned no access_token.');
    }

    $token = $data['access_token'];
    // Expire our copy a minute early so we never present a token that dies
    // mid-request.
    $expires_in = (int) ($data['expires_in'] ?? 3600);
    $this->cache->set(self::TOKEN_CID, $token, time() + max(60, $expires_in - 60));
    return $token;
  }

  /**
   * The application permissions actually granted to this app registration.
   *
   * Read from the `roles` claim of our own token — no extra Graph permission is
   * needed, which is what makes this usable as a diagnostic while the app is
   * still missing everything else.
   *
   * @return string[]
   */
  public function getGrantedRoles(): array {
    $parts = explode('.', $this->getAccessToken());
    if (count($parts) < 2) {
      return [];
    }
    $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), TRUE) ?: [];
    return $claims['roles'] ?? [];
  }

  /**
   * Whether the app can write to site libraries.
   */
  public function hasSitesWritePermission(): bool {
    $roles = $this->getGrantedRoles();
    return in_array('Sites.ReadWrite.All', $roles, TRUE)
      || in_array('Sites.Manage.All', $roles, TRUE)
      || in_array('Sites.FullControl.All', $roles, TRUE);
  }

  /**
   * The target SharePoint site.
   *
   * @return array
   *   The Graph site resource.
   */
  public function getSite(): array {
    return $this->request('GET', '/sites/' . $this->siteId);
  }

  /**
   * The target site's default document library.
   *
   * @return array
   *   The Graph drive resource (id, name, driveType, quota…).
   */
  public function getDrive(): array {
    return $this->request('GET', '/sites/' . $this->siteId . '/drive');
  }

  /**
   * Upload a .docx into the configured folder, replacing any file of that name.
   *
   * Simple upload (PUT …/content) is used deliberately: it is a single request
   * and is valid well beyond any realistic template size. Files over ~250 MB
   * would need a resumable upload session, which templates will never approach.
   *
   * @param string $filename
   *   Target file name, e.g. "affidavit-of-service.docx".
   * @param string $bytes
   *   Raw file contents.
   *
   * @return array
   *   The created/updated driveItem (id, name, webUrl, size, eTag…).
   */
  public function uploadDocx(string $filename, string $bytes): array {
    $path = $this->folder . '/' . $this->safeName($filename);
    // The ':' delimiters are Graph's path-addressing syntax; the segment between
    // them is a path, so its slashes must survive rawurlencode per-segment.
    $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));

    return $this->request(
      'PUT',
      '/sites/' . $this->siteId . '/drive/root:/' . $encoded . ':/content',
      [
        'headers' => [
          'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
        'body' => $bytes,
      ]
    );
  }

  /**
   * The id of the target site's default document library.
   */
  public function getDriveId(): string {
    return (string) ($this->getDrive()['id'] ?? '');
  }

  /**
   * Base path for addressing one item, scoped to an explicit drive.
   *
   * Everything after upload addresses items by drive id rather than by site,
   * because a driveItem id is only unique WITHIN its drive. Resolving through
   * the site again would silently target whatever the default library happens
   * to be today, which is not necessarily the one the file went into.
   */
  protected function itemPath(string $drive_id, string $item_id): string {
    return '/drives/' . rawurlencode($drive_id) . '/items/' . rawurlencode($item_id);
  }

  /**
   * Fetch a driveItem's metadata.
   */
  public function getItem(string $drive_id, string $item_id): array {
    return $this->request('GET', $this->itemPath($drive_id, $item_id));
  }

  /**
   * Download a driveItem's current contents.
   *
   * This is the "save back to the server" step: Word for the web autosaves into
   * SharePoint continuously, so whatever this returns is the author's latest
   * state — there is no unsaved-changes case to worry about.
   *
   * @return string
   *   Raw bytes.
   */
  public function downloadItem(string $drive_id, string $item_id): string {
    return $this->request(
      'GET',
      $this->itemPath($drive_id, $item_id) . '/content',
      ['raw' => TRUE]
    );
  }

  /**
   * Delete a driveItem.
   *
   * Called once the edited bytes are safely on our server, so the SharePoint
   * library does not accumulate copies of every editing session.
   */
  public function deleteItem(string $drive_id, string $item_id): void {
    $this->request('DELETE', $this->itemPath($drive_id, $item_id));
  }

  /**
   * The URL that opens this item in Word for the web, ready to edit.
   *
   * NOTE: this is opened in a NEW TAB, not an iframe. Microsoft restricts
   * framing of an editable Office for the web session to its own origins
   * (frame-ancestors); the only embeddable actions SharePoint offers
   * (embedview / interactivepreview) are read-only. Editable embedding needs
   * WOPI, which is Cloud Storage Partner Program only. If that ever changes,
   * this method is the single place to change.
   *
   * ⚠ The `action` parameter must be REPLACED, never appended. Graph already
   * returns webUrl in the form
   *   …/_layouts/15/Doc.aspx?sourcedoc={GUID}&file=x.docx&action=default&mobileredirect=true
   * so appending "&action=edit" yields a URL carrying two `action` values.
   * SharePoint honours the FIRST — `default` — and opens the read-only viewer,
   * which looks exactly like a permission problem but is not.
   *
   * @param array $item
   *   A driveItem as returned by uploadDocx()/getItem().
   */
  public function getEditUrl(array $item): string {
    $url = (string) ($item['webUrl'] ?? '');
    if ($url === '') {
      return '';
    }

    $parts = parse_url($url);
    if ($parts === FALSE || empty($parts['host'])) {
      return $url;
    }

    $query = [];
    if (!empty($parts['query'])) {
      parse_str($parts['query'], $query);
    }
    $query['action'] = 'edit';

    $rebuilt = (($parts['scheme'] ?? 'https') . '://' . $parts['host']);
    if (!empty($parts['port'])) {
      $rebuilt .= ':' . $parts['port'];
    }
    $rebuilt .= $parts['path'] ?? '';
    $rebuilt .= '?' . http_build_query($query);

    return $rebuilt;
  }

  /**
   * Create a sharing link that authorises editing of ONE item.
   *
   * The returned URL carries an opaque share token, so it is a capability, not
   * an identity: it grants edit on this item and nothing else. With
   * scope 'anonymous' the browser needs no Microsoft session at all, which is
   * the only mechanism that lets unlicensed staff author a template. Verified
   * against this tenant 2026-08-24 — the link opened Word in edit mode in a
   * clean browser, and the site root stayed inaccessible from that session.
   *
   * Tenants can forbid anonymous links (SharePoint admin centre → external
   * sharing below "Anyone"), in which case this throws and the caller should
   * fall back to getEditUrl() for signed-in staff.
   *
   * @param string $drive_id
   *   The drive holding the item.
   * @param string $item_id
   *   The driveItem id.
   * @param string $scope
   *   'anonymous' (anyone with the link, no sign-in) or 'organization' (any
   *   signed-in tenant user).
   * @param int $ttl
   *   Seconds until the link expires; 0 leaves it open-ended. A bearer URL
   *   should not outlive the editing session that needed it.
   *
   * @return string
   *   The shareable edit URL.
   */
  public function createEditLink(string $drive_id, string $item_id, string $scope = 'anonymous', int $ttl = 0): string {
    $payload = ['type' => 'edit', 'scope' => $scope];
    if ($ttl > 0) {
      $payload['expirationDateTime'] = gmdate('Y-m-d\TH:i:s\Z', time() + $ttl);
    }

    $result = $this->request(
      'POST',
      $this->itemPath($drive_id, $item_id) . '/createLink',
      ['json' => $payload]
    );
    return $result['link']['webUrl'] ?? '';
  }

  /**
   * The URL to hand the author for editing this item.
   *
   * Prefers an expiring anonymous link, so that authoring never depends on the
   * person at the keyboard holding Microsoft credentials — otherwise only
   * whoever knows the tenant login could use the feature at all.
   *
   * Three attempts, degrading rather than failing:
   *   1. anonymous + expiry — what we want.
   *   2. anonymous, open-ended — some tenants reject an explicit
   *      expirationDateTime that conflicts with their own link policy.
   *   3. the plain item URL — needs a signed-in tenant user, but is better
   *      than telling the author the document cannot be opened.
   *
   * @return array
   *   ['url' => string, 'anonymous' => bool].
   */
  public function getAuthoringUrl(array $item, string $drive_id, string $item_id, int $ttl = 3600): array {
    foreach ([$ttl, 0] as $attempt_ttl) {
      try {
        $url = $this->createEditLink($drive_id, $item_id, 'anonymous', $attempt_ttl);
        if ($url !== '') {
          return ['url' => $url, 'anonymous' => TRUE];
        }
      }
      catch (\Throwable $e) {
        $this->logger->info(
          'GraphDriveClient: anonymous edit link refused for @item (ttl @ttl): @msg',
          ['@item' => $item_id, '@ttl' => $attempt_ttl, '@msg' => $e->getMessage()]
        );
      }
    }

    return ['url' => $this->getEditUrl($item), 'anonymous' => FALSE];
  }

  /**
   * A one-shot connectivity report for the admin status page.
   *
   * Never throws — every failure is captured as a message, because the whole
   * point of this method is to be readable while things are still broken.
   *
   * @return array
   *   Keys: configured, token, roles, has_sites_write, site, drive, errors.
   */
  public function diagnostics(): array {
    $out = [
      'configured' => $this->isConfigured(),
      'token' => FALSE,
      'roles' => [],
      'has_sites_write' => FALSE,
      'site' => NULL,
      'drive' => NULL,
      'folder' => $this->folder,
      'site_id' => $this->siteId,
      'errors' => [],
    ];

    if (!$out['configured']) {
      $out['errors'][] = 'Missing MS_GRAPH_TENANT_ID / MS_GRAPH_CLIENT_ID / MS_GRAPH_CLIENT_SECRET in .env.';
      return $out;
    }

    try {
      $this->getAccessToken();
      $out['token'] = TRUE;
      $out['roles'] = $this->getGrantedRoles();
      $out['has_sites_write'] = $this->hasSitesWritePermission();
    }
    catch (\Throwable $e) {
      $out['errors'][] = 'Token: ' . $e->getMessage();
      return $out;
    }

    foreach (['site' => 'getSite', 'drive' => 'getDrive'] as $key => $method) {
      try {
        $out[$key] = $this->{$method}();
      }
      catch (\Throwable $e) {
        $out['errors'][] = ucfirst($key) . ': ' . $e->getMessage();
      }
    }

    return $out;
  }

  /**
   * Issue a Graph request and decode the result.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   Path below the Graph version root, starting with '/'.
   * @param array $options
   *   Guzzle options, plus 'raw' => TRUE to get the body back as a string
   *   instead of decoded JSON (used for file downloads).
   *
   * @return array|string
   *   Decoded JSON, or the raw body when 'raw' was set.
   *
   * @throws \RuntimeException
   */
  protected function request(string $method, string $path, array $options = []) {
    $raw = !empty($options['raw']);
    unset($options['raw']);

    $options['headers'] = ($options['headers'] ?? []) + [
      'Authorization' => 'Bearer ' . $this->getAccessToken(),
      'Accept' => 'application/json',
    ];
    // Graph 302-redirects downloads to a pre-signed storage URL that must be
    // fetched WITHOUT our Authorization header; Guzzle forwards headers across
    // redirects by default, which the storage host rejects.
    $options['allow_redirects'] = [
      'max' => 5,
      'strict' => TRUE,
      'referer' => FALSE,
      'protocols' => ['https'],
    ];

    try {
      $response = $this->httpClient->request($method, self::GRAPH . $path, $options);
    }
    catch (RequestException $e) {
      $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
      $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';

      // 401 almost always means a rotated secret or a token cached across a
      // permission change. Drop it so the next attempt re-mints.
      if ($status === 401) {
        $this->resetToken();
      }

      $this->logger->error('GraphDriveClient: @method @path failed (@status): @body', [
        '@method' => $method,
        '@path' => $path,
        '@status' => $status,
        '@body' => $body,
      ]);
      throw new \RuntimeException($this->explain($status, $body, $e->getMessage()));
    }

    $body = (string) $response->getBody();
    if ($raw) {
      return $body;
    }
    // DELETE returns 204 with no body.
    return $body === '' ? [] : (json_decode($body, TRUE) ?: []);
  }

  /**
   * Turn a Graph error into something an administrator can act on.
   *
   * Graph's own messages are frequently generic ("Access denied"), and the
   * cause here is nearly always one specific missing grant, so say so.
   */
  protected function explain(int $status, string $body, string $fallback): string {
    $decoded = json_decode($body, TRUE) ?: [];
    $code = $decoded['error']['code'] ?? '';
    $message = $decoded['error']['message'] ?? '';

    if ($status === 403) {
      return 'Microsoft Graph refused the request (403 ' . $code . '). The app registration is most '
        . 'likely missing the Application permission Sites.ReadWrite.All, or admin consent has not '
        . 'been granted for it. If consent was just granted, clear the cached token — a token minted '
        . 'before the grant keeps its old permissions for up to an hour. '
        . ($message !== '' ? 'Graph said: ' . $message : '');
    }
    if ($status === 404) {
      return 'Microsoft Graph could not find the site, library or item (404 ' . $code . '). '
        . 'Check MS_GRAPH_SITE_ID. ' . ($message !== '' ? 'Graph said: ' . $message : '');
    }
    if ($status === 401) {
      return 'Microsoft Graph rejected our credentials (401 ' . $code . '). The client secret may have '
        . 'expired or been rotated. ' . ($message !== '' ? 'Graph said: ' . $message : '');
    }
    return 'Microsoft Graph request failed (' . ($status ?: 'no response') . ') '
      . ($message !== '' ? $message : $fallback);
  }

  /**
   * Strip anything that would break Graph path addressing or SharePoint naming.
   */
  protected function safeName(string $filename): string {
    // SharePoint rejects " * : < > ? / \ | and leading/trailing whitespace.
    $clean = preg_replace('/[\x00-\x1F"\*:<>\?\/\\\\\|]+/u', '-', $filename);
    $clean = trim(preg_replace('/\s+/u', ' ', $clean));
    $clean = ltrim($clean, '.~');
    if ($clean === '') {
      $clean = 'template.docx';
    }
    if (!preg_match('/\.docx$/i', $clean)) {
      $clean .= '.docx';
    }
    return $clean;
  }

}
