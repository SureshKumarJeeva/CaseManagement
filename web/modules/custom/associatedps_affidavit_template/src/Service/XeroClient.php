<?php

namespace Drupal\associatedps_affidavit_template\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Exception\RequestException;

class XeroClient {

  protected ClientInterface $httpClient;
  protected Connection $database;

  /** Xero endpoints */
  protected string $apiBase;
  protected string $authorizeUrl;
  protected string $tokenUrl;
  protected string $connectionsUrl;
  protected string $servicesAPIBase;
  protected string $servicesAPIPath;
  protected string $connectionsPath;
  protected string $invoicesPath;
  protected string $contactsPath;

  /** OAuth credentials */
  protected string $clientId;
  protected string $clientSecret;
  protected string $redirectUri;
  protected string $tokenRedirectUri;
  protected array $scopes;
  protected string $state;

  public function __construct(
    ClientInterface $http_client,
    Connection $database
  ) {
    $this->httpClient = $http_client;
    // $this->logger = $logger_factory->get('xero_integration');
    $this->database = $database;

    // Load ENV config
    $this->apiBase        = getenv('XERO_API_BASE');
    $this->authorizeUrl  = getenv('XERO_OAUTH_AUTHORIZE');
    $this->tokenUrl      = getenv('XERO_OAUTH_TOKEN');
    $this->servicesAPIBase = getenv('XERO_SERVICES_API_BASE');
    $this->servicesAPIPath = getenv('XERO_SERVICES_API_PATH');
    $this->connectionsPath = getenv('XERO_CONNECTIONS_END_POINT');
    $this->invoicesPath = getenv('XERO_INVOICES_END_POINT');
    $this->contactsPath = getenv('XERO_CONTACTS_END_POINT');

    $this->clientId      = getenv('XERO_CLIENT_ID');
    $this->clientSecret = getenv('XERO_CLIENT_SECRET');
    $this->redirectUri  = getenv('XERO_REDIRECT_URI');
    // $this->tokenRedirectUri = getenv('XERO_TOKEN_REDIRECT_URI');
    $this->scopes       = explode(' ', getenv('XERO_SCOPES'));
    $this->state        = getenv('XERO_STATE'); 
  }

  /*
  * getter for client ID
  */
  public function getClientId(): string {
    return getenv('XERO_CLIENT_ID');
  }

  /*
  * getter for client Secret
  */
  public function getClientSecret(): string {
    return getenv('XERO_CLIENT_SECRET');
  }

  /*
  * setter for client ID
  */
  public function setClientId(string $clientId): void {
    $this->clientId = setenv('XERO_CLIENT_ID', $clientId);
  }

    /*
  * setter for client Secret
  */
  public function setClientSecret(string $clientSecret): void {
    $this->clientSecretsetenv('XERO_CLIENT_SECRET', $clientSecret);
  }


  /*  
  * Get Authorization URL to redirect user for Xero OAuth2 flow.
  */
  public function getAuthorizationUrl(): string {
    \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Get Authorization URL');
    try{
      $queryParams = http_build_query([
        'response_type' => 'code',
        'client_id' => $this->clientId,
        'redirect_uri' => $this->redirectUri,
        'scope' => implode(' ', $this->scopes),
        'state' => $this->state,
      ]);

      return $this->authorizeUrl . '?' . $queryParams;
    }catch (\Exception $e) {
        \Drupal::logger('associatedps_affidavit_template_Xero')->error('Xero getAuthorizationUrl error: @msg', ['@msg' => $e->getMessage()]);
          return '';
      }
  }

  /**
   * Fetch Access token from Xero and store it in database.
   */
  public function fetchAccessToken(string $authorizationCode): array {
    \Drupal::logger('associatedps_affidavit_template_Xero')->notice('fetch Access Token');
    try {
      \Drupal::logger('associatedps_affidavit_template')->notice('Fetching Xero access token with code: @code', ['@code' => $authorizationCode]);
      \Drupal::logger('associatedps_affidavit_template')->notice('Scopes: @scopes', ['@scopes' => implode(' ', $this->scopes)]);
      //$authHeader = base64_encode($this->clientId . ':' . $this->clientSecret); 
      // need to identify apparopriate base64 encode version as above base64_encode generates an incorrect string
      $authHeader = "MzAwMUZFRDk4MzI0NDdBN0JGNDFEMjlDM0MyODUyNTM6R18wVXVEMm9sX2QtTktESjJCMThSR1Mzc3FzZHBvSVR5WUJfU3AzR003T3lFdWp5";
      $response = $this->httpClient->post($this->tokenUrl, [
        'headers' => [
          'Authorization' => "Basic MzAwMUZFRDk4MzI0NDdBN0JGNDFEMjlDM0MyODUyNTM6R18wVXVEMm9sX2QtTktESjJCMThSR1Mzc3FzZHBvSVR5WUJfU3AzR003T3lFdWp5",
          'Content-Type' => 'application/x-www-form-urlencoded',
          'Accept' => 'application/json',
        ],
        'form_params' => [
          'grant_type' => 'authorization_code',
          'code' => $authorizationCode,
          'redirect_uri' => $this->redirectUri,
        ],
      ]);
      \Drupal::logger('associatedps_affidavit_template')->notice('Xero token response received.');
      $data = json_decode($response->getBody(), TRUE);
      $this->storeTokens($data);

      return $data;
    }
    catch (\RequestException $e) {
      $request = $e->getRequest();
      \Drupal::logger('xero_oauth')->error('Request URL: @url', [
        '@url' => (string) $request->getUri(),
      ]);

      \Drupal::logger('xero_oauth')->error('Headers: <pre>@h</pre>', [
        '@h' => print_r($request->getHeaders(), TRUE),
      ]);

      \Drupal::logger('xero_oauth')->error('Body: @b', [
        '@b' => (string) $request->getBody(),
      ]);
      \Drupal::logger('associatedps_affidavit_template')->error('Xero fetchAccessToken error: @msg', ['@msg' => $e->getMessage()]);
      throw $e;
    }
  }

  /*
  * Database related functions
  * Store tokens into database
  */
  protected function storeTokens(array $data): void {
    \Drupal::logger('associatedps_affidavit_template_Xero')->notice('store Tokens');
    try{
      $crypto = \Drupal::service('associatedps_affidavit_template.token_crypto');
      \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Access token before encryption: @access', ['@access' => $data['access_token']]);
      \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Refresh token before encryption: @refresh', ['@refresh' => $data['refresh_token']]);
      $encrypted_access_token = $crypto->encrypt($data['access_token']);
      $encrypted_refresh_token = $crypto->encrypt($data['refresh_token']);

      $tenantId = $this->fetchTenantId($data['access_token']);

      $this->database->merge('xero_tokens')
        ->key(['id' => 1])
        ->fields([
          'token_type' => $data['token_type'],
          'access_token' => $encrypted_access_token,
          'refresh_token' => $encrypted_refresh_token,
          'tenant_id' => $tenantId,
          'expires_at' => time() + $data['expires_in'],
          'scope' => $data['scope'],
          'created' => time(),
          'updated' => time(),
        ])
        ->execute();
    }catch (\Exception $e) {
      \Drupal::logger('associatedps_affidavit_template_Xero')->error('Xero storeTokens error: @msg', ['@msg' => $e->getMessage()]);
        return;
    }
  }

  /*
  * Get tenant Id from Xero connections endpoint.
  */
  public function fetchTenantId(string $accessToken): string {
    \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Fetch tenant ID');
    try{
      $response = $this->httpClient->get($this->servicesAPIBase . $this->connectionsPath, [
        'headers' => [
          'Authorization' => "Bearer {$accessToken}",
          'Accept' => 'application/json',
        ],
      ]);

      $connections = json_decode($response->getBody(), TRUE);

      return $connections[0]['tenantId'] ?? '';
    }catch (\Exception $e) {
      \Drupal::logger('associatedps_affidavit_template_Xero')->error('Xero fetchTenantId error: @msg', ['@msg' => $e->getMessage()]);
        return NULL;
    }
  }

  /*
  * Refresh Access token using refresh token.
  */
  public function refreshAccessToken(): array {
    \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Fetch refresh access token');
    try{
      $refreshToken = $this->getStoredRefreshToken();

      $response = $this->httpClient->post($this->tokenUrl, [
        'headers' => [
          'Authorization' => "Basic MzAwMUZFRDk4MzI0NDdBN0JGNDFEMjlDM0MyODUyNTM6R18wVXVEMm9sX2QtTktESjJCMThSR1Mzc3FzZHBvSVR5WUJfU3AzR003T3lFdWp5",
          'Content-Type' => 'application/x-www-form-urlencoded',
          'Accept' => 'application/json',
        ],
        'form_params' => [
          'grant_type' => 'refresh_token',
          'refresh_token' => $refreshToken,
        ],
      ]);

      $data = json_decode($response->getBody(), TRUE);

      // IMPORTANT: store NEW refresh token (rotation)
      $this->storeTokens($data);

      return $data;
    }catch (\Exception $e) {
      \Drupal::logger('associatedps_affidavit_template_Xero')->error('Xero refresh access token error: @msg', ['@msg' => $e->getMessage()]);
        return ['error' => 'Unable to refresh tokens'];
    }
  }

  /*
  * Function to get invoice details for the tenant
  */
  public function getInvoices(): array {
    \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Fetch Invoices');
    try{
      $tokenData = $this->getValidAccessToken();

      $response = $this->httpClient->get($this->servicesAPIBase .$this->servicesAPIPath. $this->invoicesPath, [
        'headers' => [
          'Authorization' => 'Bearer ' . $tokenData['access_token'],
          'xero-tenant-id' => $tokenData['tenant_id'],
          'Accept' => 'application/json',
        ],
      ]);

      return json_decode($response->getBody(), TRUE);
    }catch (\Exception $e) {
      \Drupal::logger('associatedps_affidavit_template_Xero')->error('Xero getInvoices error: @msg', ['@msg' => $e->getMessage()]);
        return ['error' => 'Unable to fetch Invoices'];
    }
  }

  /*
  * Function to get contacts details for the tenant_id
  */
  public function getContacts(): \SimpleXMLElement {
    \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Fetch contacts');
    try{
      $tokenData = $this->getValidAccessToken();

      $response = $this->httpClient->get($this->servicesAPIBase .$this->servicesAPIPath. $this->contactsPath, [
        'headers' => [
          'Authorization' => 'Bearer ' . $tokenData['access_token'],
          'xero-tenant-id' => $tokenData['tenant_id'],
          'Accept' => 'application/xml',
        ],
      ]);
      // $xml_response_contacts = $response->getBody();
      $xml_contacts = new \SimpleXMLElement($response->getBody());
      return $xml_contacts;
    }catch (\Exception $e) {
      \Drupal::logger('associatedps_affidavit_template_Xero')->error('Xero getContacts error: @msg', ['@msg' => $e->getMessage()]);
        return new \SimpleXMLElement('<error>Unable to fetch contacts</error>');
    }
}


  /*
  * Function that raise request to Xero to create invoice
  */
  public function generatePaidInvoice($invoicePayload): array {
    \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Generate Paid invoice');
    try{
      $tokenData = $this->getValidAccessToken();

      $response = $this->httpClient->put($this->servicesAPIBase . $this->servicesAPIPath . $this->invoicesPath, [
        'headers' => [
          'Authorization' => 'Bearer ' . $tokenData['access_token'],
          'xero-tenant-id' => $tokenData['tenant_id'],
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
        'body' => $invoicePayload,
      ]);
      $json_response_invoices = $response->getBody();
      $invoices = json_decode($json_response_invoices, TRUE);
      \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Invoice generated with ID: @id', ['@id' => print_r($invoices, TRUE)]);
      $invoiceId = $invoices['Invoices'][0]['InvoiceID'];
      return [
        'message' => 'Invoice generated with ID: ' . $invoiceId,
        'invoiceId' => $invoiceId,
      ];
    }catch (\GuzzleHttp\Exception\RequestException $e) {
      $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : 'No response body';
      $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 'N/A';
      \Drupal::logger('associatedps_affidavit_template_Xero')->error('Xero generatePaidInvoice HTTP @status: @body', [
        '@status' => $statusCode,
        '@body' => $responseBody,
      ]);
      return ['error' => 'Unable to generate paid invoice (HTTP ' . $statusCode . '): ' . $responseBody];
    }catch (\Exception $e) {
      \Drupal::logger('associatedps_affidavit_template_Xero')->error('Xero generatePaidInvoice Exception: @msg', ['@msg' => $e->getMessage()]);
        return ['error' => 'Unable to generate paid invoice: ' . $e->getMessage()];
    }
  }


  /*
  * Whether the site has been authorized against Xero (i.e. a token row exists).
  *
  * Used by callers (e.g. hook_node_insert auto-sync) to skip silently when
  * Xero hasn't been connected yet, instead of generating a noisy HTTP error.
  */
  public function isConnected(): bool {
    try {
      $count = (int) $this->database
        ->select('xero_tokens', 'x')
        ->condition('id', 1)
        ->countQuery()
        ->execute()
        ->fetchField();
      return $count > 0;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /*
  * Create or update contacts in Xero (bulk).
  *
  * Uses POST with summarizeErrors=false so a single invalid row does not
  * abort the whole batch — Xero returns per-contact ValidationErrors that
  * the caller can surface to the user.
  *
  * @param array $contacts
  *   Associative array of Xero Contact rows (see Xero "Contacts" API).
  *
  * @return array
  *   Decoded JSON response from Xero, or ['error' => '...'] on failure.
  */
  public function createContacts(array $contacts): array {
    \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Create contacts: @count rows', ['@count' => count($contacts)]);
    try {
      $tokenData = $this->getValidAccessToken();

      $url = $this->servicesAPIBase . $this->servicesAPIPath . $this->contactsPath . '?summarizeErrors=false';
      $response = $this->httpClient->post($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $tokenData['access_token'],
          'xero-tenant-id' => $tokenData['tenant_id'],
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
        'body' => json_encode(['Contacts' => $contacts]),
      ]);

      return json_decode($response->getBody(), TRUE) ?? [];
    }
    catch (\GuzzleHttp\Exception\RequestException $e) {
      $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : 'No response body';
      $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 'N/A';
      \Drupal::logger('associatedps_affidavit_template_Xero')->error('Xero createContacts HTTP @status: @body', [
        '@status' => $statusCode,
        '@body' => $responseBody,
      ]);
      return ['error' => 'HTTP ' . $statusCode . ': ' . $responseBody];
    }
    catch (\Exception $e) {
      \Drupal::logger('associatedps_affidavit_template_Xero')->error('Xero createContacts error: @msg', ['@msg' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /*
  * Once the access token is expired, it need to be refreshed
  */
  protected function getValidAccessToken(): array {
    $record = $this->getStoredTokens();
    \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Token expiry time: @expiry', ['@expiry' => $record['expires_at']]);
    if ($record['expires_at'] < time()) {
      \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Token Expired - current time: @expiry', ['@expiry' => time()]);
      \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Access token before refresh: @access', ['@access' => $record['access_token']]);
      \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Refresh token before refresh: @refresh', ['@refresh' => $record['refresh_token']]);
      $data = $this->refreshAccessToken();
      $record = $this->getStoredTokens();
      \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Access token after refresh: @access', ['@access' => $record['access_token']]);
      \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Refresh token after refresh: @refresh', ['@refresh' => $record['refresh_token']]);
    }else{
      \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Token Not Expired - current time: @expiry', ['@expiry' => time()]);
      \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Access token: @access', ['@access' => $record['access_token']]);
      \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Refresh token: @refresh', ['@refresh' => $record['refresh_token']]);
    }

    return $record;
  }

  /* 
  * Retrieve stored tokens from database
  */
  protected function getStoredTokens(): array {
    try{
      //fetch tokens from database
      $data = (array) $this->database
      ->select('xero_tokens', 'x')
      ->fields('x')
      ->condition('id', 1)
      ->execute()
      ->fetchAssoc();

      //Decrypt tokens before returning
      $crypto = \Drupal::service('associatedps_affidavit_template.token_crypto');
      \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Access token before decryption: @access', ['@access' => $data['access_token']]);
      \Drupal::logger('associatedps_affidavit_template_Xero')->notice('Refresh token before decryption: @refresh', ['@refresh' => $data['refresh_token']]);
      $decrypted_access_token = $crypto->decrypt($data['access_token']);
      $decrypted_refresh_token = $crypto->decrypt($data['refresh_token']);
      $data['access_token'] = $decrypted_access_token;
      $data['refresh_token'] = $decrypted_refresh_token;

      return $data;

    }catch (\Exception $e) {
      \Drupal::logger('associatedps_affidavit_template_Xero')->error('Xero getStoredTokens error: @msg', ['@msg' => $e->getMessage()]);
        return ['error' => 'Unable to fetch stored tokens'];
    }
  }

  protected function getStoredRefreshToken(): string {
    return $this->getStoredTokens()['refresh_token'];
  }

}