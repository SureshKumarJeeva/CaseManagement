<?php

namespace Drupal\associatedps_affidavit_template\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Firebase\JWT\JWT;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * DocuSign eSignature REST client (JWT Grant).
 *
 * Mirrors PandaDocClient in shape, with Xero-style proactive token refresh.
 * Tokens are minted on demand from a JWT assertion (no refresh tokens in JWT
 * grant — every refresh is a fresh mint signed with the integration's RSA
 * private key).
 */
class DocuSignClient {

  protected ClientInterface $httpClient;
  protected Connection $database;
  protected Settings $settings;
  protected LoggerChannelInterface $logger;

  /** OAuth / JWT config (env-driven). */
  protected string $integrationKey;
  protected string $userId;
  protected string $oauthBase;
  protected string $apiBaseOverride;
  protected string $accountIdOverride;
  protected string $redirectUri;
  protected string $scopes;
  protected string $privateKeyPath;
  protected string $privateKeyInline;

  /** Owner placeholder recipient (never emailed — envelopes stay as drafts). */
  protected string $ownerEmail;
  protected string $ownerName;

  /** Connect (webhook) HMAC key. */
  protected string $connectHmacKey;

  public function __construct(
    ClientInterface $http_client,
    Connection $database,
    Settings $settings,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->httpClient = $http_client;
    $this->database = $database;
    $this->settings = $settings;
    $this->logger = $logger_factory->get('docusign');

    $this->integrationKey = getenv('DOCUSIGN_INTEGRATION_KEY') ?: '';
    $this->userId = getenv('DOCUSIGN_USER_ID') ?: '';
    $this->oauthBase = rtrim(getenv('DOCUSIGN_OAUTH_BASE') ?: 'https://account-d.docusign.com', '/');
    $this->apiBaseOverride = rtrim(getenv('DOCUSIGN_API_BASE') ?: '', '/');
    $this->accountIdOverride = getenv('DOCUSIGN_ACCOUNT_ID') ?: '';
    $this->redirectUri = getenv('DOCUSIGN_REDIRECT_URI') ?: '';
    $this->scopes = getenv('DOCUSIGN_JWT_SCOPES') ?: 'signature impersonation';
    $this->privateKeyPath = getenv('DOCUSIGN_PRIVATE_KEY_PATH') ?: '';
    $this->privateKeyInline = getenv('DOCUSIGN_PRIVATE_KEY') ?: '';
    $this->ownerEmail = getenv('DOCUSIGN_OWNER_EMAIL') ?: '';
    $this->ownerName = getenv('DOCUSIGN_OWNER_NAME') ?: 'APS Admin';
    $this->connectHmacKey = getenv('DOCUSIGN_CONNECT_HMAC_KEY') ?: '';
  }

  /**
   * Build the one-time consent URL.
   *
   * The user must visit this URL once per integration key + scope set and
   * approve impersonation. Subsequent JWT mints succeed silently.
   */
  public function getAuthorizationUrl(): string {
    $params = http_build_query([
      'response_type' => 'code',
      'scope' => $this->scopes,
      'client_id' => $this->integrationKey,
      'redirect_uri' => $this->redirectUri,
    ]);
    return $this->oauthBase . '/oauth/auth?' . $params;
  }

  /**
   * Mint a fresh access token by signing a JWT assertion and exchanging it.
   *
   * Persists the result via storeTokens().
   *
   * @return array Stored token row (decrypted) or ['error' => ...].
   */
  public function mintJwtAccessToken(): array {
    if ($this->integrationKey === '' || $this->userId === '') {
      $this->logger->error('mintJwtAccessToken: DOCUSIGN_INTEGRATION_KEY or DOCUSIGN_USER_ID is empty.');
      return ['error' => 'DocuSign integration is not configured (missing integration key or user id).'];
    }

    $privateKey = $this->loadPrivateKey();
    if ($privateKey === '') {
      return ['error' => 'DocuSign private key is not readable. Check DOCUSIGN_PRIVATE_KEY_PATH or DOCUSIGN_PRIVATE_KEY in .env.'];
    }

    $now = time();
    $payload = [
      'iss' => $this->integrationKey,
      'sub' => $this->userId,
      'aud' => parse_url($this->oauthBase, PHP_URL_HOST),
      'iat' => $now,
      'exp' => $now + 3600,
      'scope' => $this->scopes,
    ];

    try {
      $assertion = JWT::encode($payload, $privateKey, 'RS256');
    }
    catch (\Throwable $e) {
      $this->logger->error('mintJwtAccessToken: JWT encode failed: @msg', ['@msg' => $e->getMessage()]);
      return ['error' => 'Failed to sign JWT assertion: ' . $e->getMessage()];
    }

    try {
      $response = $this->httpClient->post($this->oauthBase . '/oauth/token', [
        'headers' => [
          'Content-Type' => 'application/x-www-form-urlencoded',
          'Accept' => 'application/json',
        ],
        'form_params' => [
          'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
          'assertion' => $assertion,
        ],
      ]);
      $data = json_decode((string) $response->getBody(), TRUE) ?: [];
      if (empty($data['access_token'])) {
        $this->logger->error('mintJwtAccessToken: response had no access_token: @body', ['@body' => (string) $response->getBody()]);
        return ['error' => 'DocuSign returned no access_token.'];
      }
      $this->storeTokens($data);
      return $this->getStoredTokens();
    }
    catch (RequestException $e) {
      $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      $this->logger->error('mintJwtAccessToken HTTP error: @msg | body: @body', [
        '@msg' => $e->getMessage(),
        '@body' => $body,
      ]);
      // Surface consent_required clearly so the admin form can prompt the user.
      if (stripos($body, 'consent_required') !== FALSE) {
        return ['error' => 'consent_required: visit /admin/config/associatedps/docusign/authorize to grant one-time consent.'];
      }
      return ['error' => 'JWT exchange failed: ' . $e->getMessage()];
    }
  }

  /**
   * Get a valid access token (mints a fresh one if expired or missing).
   *
   * @return array Decrypted token row: access_token, account_id, base_uri, expires_at, ...
   */
  public function getValidAccessToken(): array {
    $record = $this->getStoredTokens();
    if (empty($record) || empty($record['access_token']) || (int) ($record['expires_at'] ?? 0) <= time() + 60) {
      $this->logger->notice('getValidAccessToken: minting fresh token (record missing or expired).');
      $minted = $this->mintJwtAccessToken();
      if (isset($minted['error'])) {
        return $minted;
      }
      // mintJwtAccessToken stored the row and returned it; verify it actually
      // contains the discovered fields, otherwise surface a clear error.
      if (empty($minted['account_id']) || empty($minted['base_uri']) || empty($minted['access_token'])) {
        return ['error' => 'Token minted but storage incomplete (missing account_id/base_uri). Check watchdog for storeTokens or getStoredTokens errors — most commonly the docusign_tokens DB table is missing (run drush updb).'];
      }
      return $minted;
    }
    return $record;
  }

  /**
   * GET /oauth/userinfo — used to discover account_id and base_uri after JWT.
   */
  public function getUserInfo(string $accessToken): array {
    try {
      $response = $this->httpClient->get($this->oauthBase . '/oauth/userinfo', [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
          'Accept' => 'application/json',
        ],
      ]);
      return json_decode((string) $response->getBody(), TRUE) ?: [];
    }
    catch (RequestException $e) {
      $this->logger->error('getUserInfo error: @msg', ['@msg' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Encrypt and persist a fresh token (singleton row id=1).
   */
  protected function storeTokens(array $data): void {
    try {
      $accessToken = $data['access_token'];
      $expiresIn = (int) ($data['expires_in'] ?? 3600);
      $scope = $data['scope'] ?? $this->scopes;

      // Discover account_id + base_uri (or honor env override).
      $accountId = $this->accountIdOverride;
      $baseUri = $this->apiBaseOverride;
      if ($accountId === '' || $baseUri === '') {
        $userInfo = $this->getUserInfo($accessToken);
        $account = $this->pickAccount($userInfo['accounts'] ?? []);
        if ($accountId === '') {
          $accountId = $account['account_id'] ?? '';
        }
        if ($baseUri === '') {
          $baseUri = rtrim($account['base_uri'] ?? '', '/');
        }
      }

      if ($accountId === '' || $baseUri === '') {
        $this->logger->error('storeTokens: could not resolve account_id or base_uri for the impersonated user.');
        return;
      }

      $crypto = \Drupal::service('associatedps_affidavit_template.token_crypto');
      $encrypted = $crypto->encrypt($accessToken);

      $this->database->merge('docusign_tokens')
        ->key(['id' => 1])
        ->fields([
          'user_id' => $this->userId,
          'account_id' => $accountId,
          'base_uri' => $baseUri,
          'access_token' => $encrypted,
          'expires_at' => time() + $expiresIn,
          'scope' => $scope,
          'created' => time(),
          'updated' => time(),
        ])
        ->execute();
    }
    catch (\Throwable $e) {
      $this->logger->error('storeTokens error: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * Read + decrypt the stored token row. Returns [] if none.
   */
  protected function getStoredTokens(): array {
    try {
      $row = $this->database->select('docusign_tokens', 'd')
        ->fields('d')
        ->condition('id', 1)
        ->execute()
        ->fetchAssoc();
      if (!$row) {
        return [];
      }
      $crypto = \Drupal::service('associatedps_affidavit_template.token_crypto');
      $row['access_token'] = $crypto->decrypt($row['access_token']);
      return $row;
    }
    catch (\Throwable $e) {
      $this->logger->error('getStoredTokens error: @msg', ['@msg' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Common authorization headers for DocuSign API calls.
   *
   * Returns ['headers' => [...], 'base' => '<base_uri>/restapi/v2.1/accounts/<aid>'].
   */
  protected function authContext(): array {
    $token = $this->getValidAccessToken();
    if (isset($token['error'])) {
      return ['error' => $token['error']];
    }
    return [
      'headers' => [
        'Authorization' => 'Bearer ' . $token['access_token'],
        'Accept' => 'application/json',
      ],
      'base' => $token['base_uri'] . '/restapi/v2.1/accounts/' . $token['account_id'],
      'account_id' => $token['account_id'],
      'base_uri' => $token['base_uri'],
    ];
  }

  /**
   * Pick the default DocuSign account for the user (honors is_default).
   */
  protected function pickAccount(array $accounts): array {
    foreach ($accounts as $account) {
      if (!empty($account['is_default'])) {
        return $account;
      }
    }
    return $accounts[0] ?? [];
  }

  /**
   * Load the RSA private key from path or inline env var.
   */
  protected function loadPrivateKey(): string {
    if ($this->privateKeyPath !== '' && is_readable($this->privateKeyPath)) {
      $content = @file_get_contents($this->privateKeyPath);
      return $content !== FALSE ? $content : '';
    }
    if ($this->privateKeyInline !== '') {
      return $this->privateKeyInline;
    }
    return '';
  }

  /**
   * Upload a file as a DocuSign template (synchronous; returns template ID).
   *
   * DocuSign requires every template to declare at least one recipient. We
   * inject a placeholder `Signer 1` using DOCUSIGN_OWNER_EMAIL — but because
   * envelopes generated from this template stay as drafts (status=created),
   * the placeholder is never emailed or invited to sign.
   *
   * @param string $filePath Absolute path to the uploaded file on disk.
   * @param string $name     Template name (shown in DocuSign console).
   * @param string $fileExt  File extension (docx, pdf, rtf, etc.).
   * @param string $fileName Original filename (shown in DocuSign console).
   *
   * @return array Decoded JSON response: { templateId, name, ... } or { error }.
   */
  public function uploadTemplate(string $filePath, string $name, string $fileExt, string $fileName): array {
    $ctx = $this->authContext();
    if (isset($ctx['error'])) {
      return $ctx;
    }

    if (!is_readable($filePath)) {
      return ['error' => 'uploadTemplate: file not readable at ' . $filePath];
    }

    $base64 = base64_encode((string) @file_get_contents($filePath));
    if ($base64 === '') {
      return ['error' => 'uploadTemplate: file is empty or unreadable.'];
    }

    $body = [
      'name' => $name,
      'description' => 'Uploaded from APS Drupal — ' . $fileName,
      'envelopeTemplateDefinition' => [
        'name' => $name,
        'shared' => 'false',
      ],
      'documents' => [[
        'documentId' => '1',
        'name' => $fileName,
        'fileExtension' => $fileExt,
        'documentBase64' => $base64,
      ]],
      'recipients' => [
        'signers' => [[
          'roleName' => 'Signer 1',
          'recipientId' => '1',
          'routingOrder' => '1',
          'name' => $this->ownerName,
          'email' => $this->ownerEmail,
        ]],
      ],
    ];

    try {
      $response = $this->httpClient->post($ctx['base'] . '/templates', [
        'headers' => array_merge($ctx['headers'], ['Content-Type' => 'application/json']),
        'json' => $body,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE) ?: [];
      $tid = $data['templateId'] ?? ($data['envelopeTemplateDefinition']['templateId'] ?? '');
      if ($tid === '') {
        $this->logger->error('uploadTemplate: response had no templateId. Body: @b', ['@b' => (string) $response->getBody()]);
        return ['error' => 'DocuSign did not return a templateId.'];
      }
      return ['templateId' => $tid] + $data;
    }
    catch (RequestException $e) {
      $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      $this->logger->error('uploadTemplate HTTP error: @msg | body: @body', [
        '@msg' => $e->getMessage(),
        '@body' => $body,
      ]);
      return ['error' => 'uploadTemplate failed: ' . $e->getMessage() . ($body ? ' — ' . $body : '')];
    }
  }

  /**
   * Create a single-use embedded-edit-view URL for a template.
   *
   * The returned URL has a ~5-minute lifetime and is single-use; reload via
   * a new call to refresh.
   *
   * @param string $templateId DocuSign template GUID.
   * @param string $returnUrl  URL the iframe redirects to after Save/Cancel.
   *
   * @return array { url } or { error }.
   */
  public function createTemplateEditView(string $templateId, string $returnUrl): array {
    $ctx = $this->authContext();
    if (isset($ctx['error'])) {
      return $ctx;
    }

    try {
      $response = $this->httpClient->post($ctx['base'] . '/templates/' . $templateId . '/views/edit', [
        'headers' => array_merge($ctx['headers'], ['Content-Type' => 'application/json']),
        'json' => ['returnUrl' => $returnUrl],
      ]);
      $data = json_decode((string) $response->getBody(), TRUE) ?: [];
      if (empty($data['url'])) {
        $this->logger->error('createTemplateEditView: response had no url. Body: @b', ['@b' => (string) $response->getBody()]);
        return ['error' => 'DocuSign did not return an edit-view URL.'];
      }
      return $data;
    }
    catch (RequestException $e) {
      $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      $this->logger->error('createTemplateEditView HTTP error: @msg | body: @body', [
        '@msg' => $e->getMessage(),
        '@body' => $body,
      ]);
      return ['error' => 'createTemplateEditView failed: ' . $e->getMessage() . ($body ? ' — ' . $body : '')];
    }
  }

  /**
   * Fetch template details (recipients, documents, tabs).
   *
   * @param string $templateId DocuSign template GUID.
   *
   * @return array Decoded JSON template object or { error }.
   */
  public function getTemplate(string $templateId): array {
    $ctx = $this->authContext();
    if (isset($ctx['error'])) {
      return $ctx;
    }

    try {
      $response = $this->httpClient->get($ctx['base'] . '/templates/' . $templateId, [
        'headers' => $ctx['headers'],
        'query' => ['include' => 'recipients,documents,tabs'],
      ]);
      return json_decode((string) $response->getBody(), TRUE) ?: [];
    }
    catch (RequestException $e) {
      $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      $this->logger->error('getTemplate HTTP error: @msg | body: @body', [
        '@msg' => $e->getMessage(),
        '@body' => $body,
      ]);
      return ['error' => 'getTemplate failed: ' . $e->getMessage()];
    }
  }

  /**
   * Create a draft envelope from a stored template, with prefilled tabs.
   *
   * The envelope is created with status="created" (draft). DocuSign does not
   * email recipients while an envelope sits in `created`, so the placeholder
   * Signer 1 role is never notified. The envelope can later be previewed via
   * the embedded sender view and downloaded as a combined PDF.
   *
   * @param string $templateId DocuSign template GUID.
   * @param string $name       Envelope name (shown in DocuSign console / email subject).
   * @param array  $textTabs   List of ['tabLabel' => ..., 'value' => ...].
   * @param array  $dateTabs   List of ['tabLabel' => ..., 'value' => ...].
   * @param string $status     'created' (draft) or 'sent'. Default 'created'.
   *
   * @return array { envelopeId, status, ... } or { error }.
   */
  public function createEnvelopeFromTemplate(
    string $templateId,
    string $name,
    array $textTabs = [],
    array $dateTabs = [],
    string $status = 'created'
  ): array {
    $ctx = $this->authContext();
    if (isset($ctx['error'])) {
      return $ctx;
    }

    $tabs = [];
    if (!empty($textTabs)) {
      $tabs['textTabs'] = array_values($textTabs);
    }
    if (!empty($dateTabs)) {
      $tabs['dateTabs'] = array_values($dateTabs);
    }

    // clientUserId marks the recipient as "embedded" / captive — DocuSign
    // does NOT email embedded recipients. This lets us safely transition
    // the envelope to status="sent" (required for prefill tab values to
    // render into the downloaded PDF) without anyone receiving a signing
    // notification. Value is a stable per-account identifier.
    $templateRole = [
      'roleName' => 'Signer 1',
      'name' => $this->ownerName,
      'email' => $this->ownerEmail,
      'clientUserId' => 'aps-embedded-' . substr(md5($this->ownerEmail), 0, 16),
    ];
    if (!empty($tabs)) {
      $templateRole['tabs'] = $tabs;
    }

    $body = [
      'templateId' => $templateId,
      'emailSubject' => $name,
      'status' => $status,
      'templateRoles' => [$templateRole],
    ];

    try {
      $response = $this->httpClient->post($ctx['base'] . '/envelopes', [
        'headers' => array_merge($ctx['headers'], ['Content-Type' => 'application/json']),
        'json' => $body,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE) ?: [];
      $eid = $data['envelopeId'] ?? '';
      if ($eid === '') {
        $this->logger->error('createEnvelopeFromTemplate: response had no envelopeId. Body: @b', ['@b' => (string) $response->getBody()]);
        return ['error' => 'DocuSign did not return an envelopeId.'];
      }
      return ['envelopeId' => $eid] + $data;
    }
    catch (RequestException $e) {
      $errBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      $this->logger->error('createEnvelopeFromTemplate HTTP error: @msg | body: @body', [
        '@msg' => $e->getMessage(),
        '@body' => $errBody,
      ]);
      return ['error' => 'createEnvelopeFromTemplate failed: ' . $e->getMessage() . ($errBody ? ' — ' . $errBody : '')];
    }
  }

  /**
   * Transition an envelope's status (typically created → sent).
   *
   * Required workaround for the DocuSign rendering quirk: prefill tab
   * values are visible in the editor while the envelope is `created`
   * but are NOT burned into the combined PDF until the envelope reaches
   * `sent` (or later). Because our placeholder signer is created with
   * `clientUserId` (embedded recipient), DocuSign does not email them
   * when we transition to `sent`.
   *
   * @param string $envelopeId DocuSign envelope GUID.
   * @param string $status     Target status (default 'sent').
   *
   * @return array Decoded response or { error }.
   */
  public function sendEnvelope(string $envelopeId, string $status = 'sent'): array {
    $ctx = $this->authContext();
    if (isset($ctx['error'])) {
      return $ctx;
    }

    try {
      $response = $this->httpClient->put($ctx['base'] . '/envelopes/' . $envelopeId, [
        'headers' => array_merge($ctx['headers'], ['Content-Type' => 'application/json']),
        'json' => ['status' => $status],
      ]);
      return json_decode((string) $response->getBody(), TRUE) ?: [];
    }
    catch (RequestException $e) {
      $errBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      $this->logger->error('sendEnvelope HTTP error: @msg | body: @body', [
        '@msg' => $e->getMessage(),
        '@body' => $errBody,
      ]);
      return ['error' => 'sendEnvelope failed: ' . $e->getMessage() . ($errBody ? ' — ' . $errBody : '')];
    }
  }

  /**
   * Fetch envelope status.
   *
   * @param string $envelopeId DocuSign envelope GUID.
   *
   * @return array Decoded JSON envelope object or { error }.
   */
  public function getEnvelopeStatus(string $envelopeId): array {
    $ctx = $this->authContext();
    if (isset($ctx['error'])) {
      return $ctx;
    }

    try {
      $response = $this->httpClient->get($ctx['base'] . '/envelopes/' . $envelopeId, [
        'headers' => $ctx['headers'],
      ]);
      return json_decode((string) $response->getBody(), TRUE) ?: [];
    }
    catch (RequestException $e) {
      $errBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      $this->logger->error('getEnvelopeStatus HTTP error: @msg | body: @body', [
        '@msg' => $e->getMessage(),
        '@body' => $errBody,
      ]);
      return ['error' => 'getEnvelopeStatus failed: ' . $e->getMessage()];
    }
  }

  /**
   * Fill values into named tabs anywhere on an envelope.
   *
   * DocuSign tab-update PUTs key tabs by tabId, NOT by tabLabel. Sending
   * a tab with only tabLabel + value either silently fails or appends a
   * malformed tab at (0,0). This method does the discovery + matching:
   *
   *   1. GET document tabs → map prefillTabs.textTabs/dateTabs tabLabel → tabId
   *   2. GET envelope recipients → for each signer, map their textTabs/
   *      dateTabs tabLabel → tabId
   *   3. PUT /envelopes/{eid}/documents/{docId}/tabs with `{prefillTabs}`
   *      for matched document tabs
   *   4. PUT /envelopes/{eid}/recipients/{rid}/tabs with `{textTabs, dateTabs}`
   *      for matched recipient tabs
   *
   * Tabs whose labels don't appear in the envelope are skipped. Templates
   * uploaded via this module are single-document (documentId="1") so we
   * only update document "1"; recipients are enumerated dynamically.
   *
   * fullNameTabs are intentionally NOT supported — they auto-fill from the
   * signer's name and can't be overridden via the API.
   *
   * @param string $envelopeId  DocuSign envelope GUID.
   * @param array  $valuesByLabel  Map of tabLabel → value (e.g.
   *                               ['Party_2.Name' => 'Denis Gerald', ...]).
   *
   * @return array { matched_count, missed_labels, errors } or { error }.
   */
  public function fillEnvelopeTabsByLabel(string $envelopeId, array $valuesByLabel): array {
    if (empty($valuesByLabel)) {
      return ['skipped' => 'no values to fill', 'matched_count' => 0];
    }

    $ctx = $this->authContext();
    if (isset($ctx['error'])) {
      return $ctx;
    }

    $matched = 0;
    $unmatchedLabels = array_fill_keys(array_keys($valuesByLabel), TRUE);
    $errors = [];

    // ----- Document prefill tabs (single-doc templates: documentId = "1"). -----
    try {
      $docResp = $this->httpClient->get(
        $ctx['base'] . '/envelopes/' . $envelopeId . '/documents/1/tabs',
        ['headers' => $ctx['headers']]
      );
      $docTabs = json_decode((string) $docResp->getBody(), TRUE) ?: [];
    }
    catch (RequestException $e) {
      $this->logger->warning('fillEnvelopeTabsByLabel: GET document tabs failed: @msg', ['@msg' => $e->getMessage()]);
      $docTabs = [];
    }

    $prefillBucket = ['textTabs' => [], 'dateTabs' => []];
    foreach (['textTabs', 'dateTabs'] as $tk) {
      foreach ($docTabs['prefillTabs'][$tk] ?? [] as $tab) {
        $label = $tab['tabLabel'] ?? '';
        $tabId = $tab['tabId'] ?? '';
        if ($label === '' || $tabId === '' || !array_key_exists($label, $valuesByLabel)) {
          continue;
        }
        $prefillBucket[$tk][] = ['tabId' => $tabId, 'value' => (string) $valuesByLabel[$label]];
        unset($unmatchedLabels[$label]);
        $matched++;
      }
    }
    $prefillBucket = array_filter($prefillBucket);
    if (!empty($prefillBucket)) {
      try {
        $this->httpClient->put(
          $ctx['base'] . '/envelopes/' . $envelopeId . '/documents/1/tabs',
          [
            'headers' => array_merge($ctx['headers'], ['Content-Type' => 'application/json']),
            'json' => ['prefillTabs' => $prefillBucket],
          ]
        );
      }
      catch (RequestException $e) {
        $errBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
        $this->logger->error('fillEnvelopeTabsByLabel PUT document tabs failed: @msg | body: @body', [
          '@msg' => $e->getMessage(),
          '@body' => $errBody,
        ]);
        $errors[] = 'document tabs: ' . $e->getMessage();
      }
    }

    // ----- Recipient-bound tabs (per signer). -----
    try {
      $recipResp = $this->httpClient->get(
        $ctx['base'] . '/envelopes/' . $envelopeId . '/recipients',
        ['headers' => $ctx['headers'], 'query' => ['include_tabs' => 'true']]
      );
      $recipients = json_decode((string) $recipResp->getBody(), TRUE) ?: [];
    }
    catch (RequestException $e) {
      $this->logger->warning('fillEnvelopeTabsByLabel: GET recipients failed: @msg', ['@msg' => $e->getMessage()]);
      $recipients = [];
    }

    foreach ($recipients['signers'] ?? [] as $signer) {
      $rid = $signer['recipientId'] ?? '';
      if ($rid === '') {
        continue;
      }
      $rTabs = $signer['tabs'] ?? [];
      $rBucket = ['textTabs' => [], 'dateTabs' => []];
      foreach (['textTabs', 'dateTabs'] as $tk) {
        foreach ($rTabs[$tk] ?? [] as $tab) {
          $label = $tab['tabLabel'] ?? '';
          $tabId = $tab['tabId'] ?? '';
          if ($label === '' || $tabId === '' || !array_key_exists($label, $valuesByLabel)) {
            continue;
          }
          $rBucket[$tk][] = ['tabId' => $tabId, 'value' => (string) $valuesByLabel[$label]];
          unset($unmatchedLabels[$label]);
          $matched++;
        }
      }
      $rBucket = array_filter($rBucket);
      if (empty($rBucket)) {
        continue;
      }
      try {
        $this->httpClient->put(
          $ctx['base'] . '/envelopes/' . $envelopeId . '/recipients/' . $rid . '/tabs',
          [
            'headers' => array_merge($ctx['headers'], ['Content-Type' => 'application/json']),
            'json' => $rBucket,
          ]
        );
      }
      catch (RequestException $e) {
        $errBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
        $this->logger->error('fillEnvelopeTabsByLabel PUT recipient tabs failed: @msg | body: @body', [
          '@msg' => $e->getMessage(),
          '@body' => $errBody,
        ]);
        $errors[] = 'recipient ' . $rid . ': ' . $e->getMessage();
      }
    }

    return [
      'matched_count' => $matched,
      'missed_labels' => array_keys($unmatchedLabels),
      'errors' => $errors,
    ];
  }

  /**
   * Create an embedded recipient (signer) view URL for an envelope.
   *
   * Uses DocuSign's `/views/recipient` endpoint — loads the document the
   * way the embedded Signer 1 sees it: prefilled tab values visible,
   * document content read-only. This matches the PandaDoc preview flow
   * we replaced (signed-session iframe for review before download).
   *
   * Endpoint history:
   * - `/views/sender` (sender view, `send=1`) — only valid on `created`
   *   envelopes. Once we transitioned to `sent` to render prefill values,
   *   sender URLs immediately redirected to returnUrl (app loaded itself
   *   recursively inside the iframe).
   * - `/views/correct` (correction view, `a=correct&advcorrect=1`) — works
   *   on sent envelopes but lands the user on "Add documents" / recipient
   *   management screens, not on the document.
   * - `/views/recipient` (current) — drops the user directly onto the
   *   document with filled tabs, which is the desired review UX.
   *
   * Requires the recipient's identifying fields to match the placeholder
   * Signer 1 set in createEnvelopeFromTemplate (name, email, clientUserId).
   * authenticationMethod=none is fine because the recipient is embedded —
   * we're already authenticated as the account owner via JWT.
   *
   * The returned URL is single-use with ~5-minute lifetime.
   *
   * @param string $envelopeId DocuSign envelope GUID.
   * @param string $returnUrl  URL the iframe redirects to on close.
   *
   * @return array { url } or { error }.
   */
  public function createSenderView(string $envelopeId, string $returnUrl): array {
    $ctx = $this->authContext();
    if (isset($ctx['error'])) {
      return $ctx;
    }

    // Must match the placeholder Signer 1 created in createEnvelopeFromTemplate.
    $clientUserId = 'aps-embedded-' . substr(md5($this->ownerEmail), 0, 16);

    try {
      $response = $this->httpClient->post($ctx['base'] . '/envelopes/' . $envelopeId . '/views/recipient', [
        'headers' => array_merge($ctx['headers'], ['Content-Type' => 'application/json']),
        'json' => [
          'returnUrl' => $returnUrl,
          'authenticationMethod' => 'none',
          'userName' => $this->ownerName,
          'email' => $this->ownerEmail,
          'clientUserId' => $clientUserId,
        ],
      ]);
      $data = json_decode((string) $response->getBody(), TRUE) ?: [];
      if (empty($data['url'])) {
        $this->logger->error('createSenderView: response had no url. Body: @b', ['@b' => (string) $response->getBody()]);
        return ['error' => 'DocuSign did not return a recipient-view URL.'];
      }
      return $data;
    }
    catch (RequestException $e) {
      $errBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      $this->logger->error('createSenderView HTTP error: @msg | body: @body', [
        '@msg' => $e->getMessage(),
        '@body' => $errBody,
      ]);
      return ['error' => 'createSenderView failed: ' . $e->getMessage() . ($errBody ? ' — ' . $errBody : '')];
    }
  }

  /**
   * Download the combined PDF of all documents in an envelope.
   *
   * Works on drafts (status=created) — the `?certificate=false` query keeps
   * the DocuSign Certificate of Completion off the output, which is what we
   * want for internal generate-prefill-download flows that aren't signed.
   *
   * @param string $envelopeId DocuSign envelope GUID.
   *
   * @return string Raw PDF bytes on success, empty string on failure.
   */
  public function downloadCombinedPdf(string $envelopeId): string {
    $ctx = $this->authContext();
    if (isset($ctx['error'])) {
      $this->logger->error('downloadCombinedPdf auth error: @e', ['@e' => $ctx['error']]);
      return '';
    }

    try {
      $response = $this->httpClient->get($ctx['base'] . '/envelopes/' . $envelopeId . '/documents/combined', [
        'headers' => array_merge($ctx['headers'], ['Accept' => 'application/pdf']),
        'query' => ['certificate' => 'false'],
      ]);
      return (string) $response->getBody();
    }
    catch (RequestException $e) {
      $errBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      $this->logger->error('downloadCombinedPdf HTTP error: @msg | body: @body', [
        '@msg' => $e->getMessage(),
        '@body' => $errBody,
      ]);
      return '';
    }
  }

  /**
   * Diagnostic info for the admin config form.
   */
  public function getDiagnostics(): array {
    return [
      'integration_key_set' => $this->integrationKey !== '',
      'user_id_set' => $this->userId !== '',
      'private_key_readable' => $this->loadPrivateKey() !== '',
      'oauth_base' => $this->oauthBase,
      'redirect_uri' => $this->redirectUri,
      'scopes' => $this->scopes,
      'owner_email' => $this->ownerEmail,
    ];
  }

}
