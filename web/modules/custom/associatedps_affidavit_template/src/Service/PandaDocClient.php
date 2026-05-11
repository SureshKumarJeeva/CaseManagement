<?php

namespace Drupal\associatedps_affidavit_template\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Database\Connection;
use GuzzleHttp\Exception\RequestException;

class PandaDocClient {

  protected ClientInterface $httpClient;
  protected Connection $database;

  protected string $apiKey;
  protected string $apiBase;
  protected bool $sandbox;

  public function __construct(
    ClientInterface $http_client,
    Connection $database
  ) {
    $this->httpClient = $http_client;
    $this->database = $database;

    $this->apiKey  = getenv('PANDADOC_API_KEY') ?: '';
    $this->apiBase = getenv('PANDADOC_API_BASE') ?: 'https://api.pandadoc.com/public/v1';
    $this->sandbox = filter_var(getenv('PANDADOC_SANDBOX'), FILTER_VALIDATE_BOOLEAN);
  }

  /**
   * Common authorization headers for all API calls.
   */
  protected function authHeaders(): array {
    return [
      'Authorization' => 'API-Key ' . $this->apiKey,
      'Accept' => '*/*',
      'content-type' => 'application/json',
    ];
  }

  /**
   * Upload a file as a PandaDoc template.
   *
   * @param string $filePath Absolute path to the file on disk.
   * @param string $name     Human-readable template name.
   * @param array  $tags     Optional tags for organisation.
   *
   * @return array Decoded JSON response with template id, status, etc.
   */
  public function uploadTemplate(string $filePath, string $name, array $tags = [], string $originalFilename = ''): array {
    \Drupal::logger('pandadoc')->notice('uploadTemplate: @name', ['@name' => $name]);
    try {
      $data = ['name' => $name];
      if (!empty($tags)) {
        $data['tags'] = $tags;
      }

      $multipart = [
        [
          'name' => 'file',
          'contents' => fopen($filePath, 'r'),
          'filename' => $originalFilename ?: basename($filePath),
        ],
        [
          'name' => 'data',
          'contents' => json_encode($data),
          'headers' => ['Content-Type' => 'application/json'],
        ],
      ];

      $response = $this->httpClient->post($this->apiBase . '/templates?upload', [
        'headers' => [
          'Authorization' => 'API-Key ' . $this->apiKey,
        ],
        'multipart' => $multipart,
      ]);

      return json_decode($response->getBody(), TRUE);
    }
    catch (RequestException $e) {
      \Drupal::logger('pandadoc')->error('uploadTemplate error: @msg', ['@msg' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Create an editing session for a template (returns E-Token for iframe).
   *
   * @param string $templateId PandaDoc template UUID.
   *
   * @return array Contains 'id' (session id) and 'expires_at'.
   */
  public function createEditingSession(string $templateId): array {
    \Drupal::logger('pandadoc')->notice('createEditingSession: @id', ['@id' => $templateId]);
    try {
      $response = $this->httpClient->post($this->apiBase . '/templates/' . $templateId . '/editing-sessions', [
        'headers' => $this->authHeaders(),
        'json' => [
          'email' => 'jsureshkumarj1@gmail.com',
        ],
      ]);

      return json_decode($response->getBody(), TRUE);
    }
    catch (RequestException $e) {
      \Drupal::logger('pandadoc')->error('createEditingSession error: @msg', ['@msg' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * List templates from PandaDoc account.
   *
   * @param string $query  Optional search query.
   * @param int    $count  Number of results per page.
   * @param int    $page   Page number (1-based).
   *
   * @return array Decoded JSON with 'results' array.
   */
  public function listTemplates(string $query = '', int $count = 50, int $page = 1): array {
    \Drupal::logger('pandadoc')->notice('listTemplates');
    try {
      $queryParams = ['count' => $count, 'page' => $page];
      if (!empty($query)) {
        $queryParams['q'] = $query;
      }

      $response = $this->httpClient->get($this->apiBase . '/templates', [
        'headers' => $this->authHeaders(),
        'query' => $queryParams,
      ]);

      return json_decode($response->getBody(), TRUE);
    }
    catch (RequestException $e) {
      \Drupal::logger('pandadoc')->error('listTemplates error: @msg', ['@msg' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Get template status.
   *
   * @param string $templateId PandaDoc template UUID.
   *
   * @return array Template info including 'status'.
   */
  public function getTemplateStatus(string $templateId): array {
    \Drupal::logger('pandadoc')->notice('getTemplateStatus: @id', ['@id' => $templateId]);
    try {
      $response = $this->httpClient->get($this->apiBase . '/templates/' . $templateId, [
        'headers' => $this->authHeaders(),
      ]);

      return json_decode($response->getBody(), TRUE);
    }
    catch (RequestException $e) {
      \Drupal::logger('pandadoc')->error('getTemplateStatus error: @msg', ['@msg' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Get template metadata/details.
   *
   * @param string $templateId PandaDoc template UUID.
   *
   * @return array Template details including fields, tokens, roles.
   */
  public function getTemplateDetails(string $templateId): array {
    \Drupal::logger('pandadoc')->notice('getTemplateDetails: @id', ['@id' => $templateId]);
    try {
      $response = $this->httpClient->get($this->apiBase . '/templates/' . $templateId . '/details', [
        'headers' => $this->authHeaders(),
      ]);

      return json_decode($response->getBody(), TRUE);
    }
    catch (RequestException $e) {
      \Drupal::logger('pandadoc')->error('getTemplateDetails error: @msg', ['@msg' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Get the default recipient details from Drupal admin user (uid=1).
   *
   * @return array Recipient array with 'email', 'first_name', 'last_name'.
   */
  protected function getDefaultRecipient(): array {
    // Use PandaDoc account owner email (must be within the organization).
    $pandadocEmail = getenv('PANDADOC_OWNER_EMAIL') ?: 'jsureshkumarj1@gmail.com';
    $adminUser = \Drupal\user\Entity\User::load(52);
    $adminName = $adminUser ? ($adminUser->getDisplayName() ?: 'Admin') : 'Admin';
    $nameParts = explode(' ', $adminName, 2);

    return [
      'email' => $pandadocEmail,
      'first_name' => $nameParts[0],
      'last_name' => $nameParts[1] ?? $nameParts[0],
    ];
  }

  /**
   * Create a document from a PandaDoc template with token values.
   *
   * @param string $templateUuid PandaDoc template UUID.
   * @param string $name         Document name.
   * @param array  $tokens       Array of ['name' => '...', 'value' => '...'].
   * @param array  $metadata     Optional metadata key-value pairs.
   *
   * @return array Document creation response with 'id', 'status', etc.
   */
  public function createDocument(string $templateUuid, string $name, array $tokens = [], array $metadata = []): array {
    \Drupal::logger('pandadoc')->notice('createDocument from template: @tmpl, name: @name', [
      '@tmpl' => $templateUuid,
      '@name' => $name,
    ]);
    try {
      // Convert tokens array to PandaDoc fields format:
      // {"FieldName": {"value": "..."}} instead of [{"name": "...", "value": "..."}]
      $fields = [];
      foreach ($tokens as $token) {
        if (!empty($token['name'])) {
          $fields[$token['name']] = ['value' => $token['value'] ?? ''];
        }
      }

      // Fetch template roles so recipient can be mapped to the correct role.
      $recipient = $this->getDefaultRecipient();
      $templateDetails = $this->getTemplateDetails($templateUuid);
      $roles = $templateDetails['roles'] ?? [];
      if (!empty($roles)) {
        $recipient['role'] = $roles[0]['name'];
      }

      $body = [
        'name' => $name,
        'template_uuid' => $templateUuid,
        'recipients' => [$recipient],
        'fields' => (object) $fields,
      ];

      if (!empty($metadata)) {
        $body['metadata'] = $metadata;
      }
      \Drupal::logger('pandadoc')->notice('createDocument body: @body', ['@body' => json_encode($body)]);
      $response = $this->httpClient->post($this->apiBase . '/documents', [
        'headers' => $this->authHeaders(),
        'json' => $body,
      ]);

      return json_decode($response->getBody(), TRUE);
    }
    catch (RequestException $e) {
      \Drupal::logger('pandadoc')->error('createDocument error: @msg', ['@msg' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Get current document status.
   *
   * @param string $documentId PandaDoc document UUID.
   *
   * @return array Document details including 'status'.
   */
  public function getDocumentStatus(string $documentId): array {
    \Drupal::logger('pandadoc')->notice('getDocumentStatus: @id', ['@id' => $documentId]);
    try {
      $response = $this->httpClient->get($this->apiBase . '/documents/' . $documentId, [
        'headers' => $this->authHeaders(),
      ]);

      return json_decode($response->getBody(), TRUE);
    }
    catch (RequestException $e) {
      \Drupal::logger('pandadoc')->error('getDocumentStatus error: @msg', ['@msg' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Send/finalize a document. Use silent=true for internal documents (no recipients).
   *
   * @param string $documentId PandaDoc document UUID.
   * @param bool   $silent     If true, skip recipient notifications.
   *
   * @return array Response from PandaDoc.
   */
  public function sendDocument(string $documentId, bool $silent = TRUE): array {
    \Drupal::logger('pandadoc')->notice('sendDocument: @id (silent: @s)', [
      '@id' => $documentId,
      '@s' => $silent ? 'yes' : 'no',
    ]);
    try {
      $response = $this->httpClient->post($this->apiBase . '/documents/' . $documentId . '/send', [
        'headers' => $this->authHeaders(),
        'json' => [
          'message' => 'Document generated via Drupal',
          'silent' => $silent,
          'recipients' => [$this->getDefaultRecipient()],
        ],
      ]);

      return json_decode($response->getBody(), TRUE);
    }
    catch (RequestException $e) {
      $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      \Drupal::logger('pandadoc')->error('sendDocument error: @msg | body: @body', [
        '@msg' => $e->getMessage(),
        '@body' => $responseBody,
      ]);
      return ['error' => $e->getMessage()];
    } 
  }

  /**
   * Create a viewing/editing session for a document (returns session ID for iframe embed).
   *
   * @param string $documentId PandaDoc document UUID.
   *
   * @return array Contains 'id' (session id) and 'expires_at'.
   */
  public function createDocumentSession(string $documentId): array {
    \Drupal::logger('pandadoc')->notice('createDocumentSession: @id', ['@id' => $documentId]);
    try {
      $recipient = $this->getDefaultRecipient();
      $response = $this->httpClient->post($this->apiBase . '/documents/' . $documentId . '/session', [
        'headers' => $this->authHeaders(),
        'json' => [
          'recipient' => $recipient['email'],
          'lifetime' => 3600,
        ],
      ]);

      return json_decode($response->getBody(), TRUE);
    }
    catch (RequestException $e) {
      $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      \Drupal::logger('pandadoc')->error('createDocumentSession error: @msg | body: @body', [
        '@msg' => $e->getMessage(),
        '@body' => $responseBody,
      ]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Download a generated document as PDF binary.
   *
   * @param string $documentId PandaDoc document UUID.
   *
   * @return string|null Raw PDF binary content, or NULL on failure.
   */
  public function downloadDocument(string $documentId): ?string {
    \Drupal::logger('pandadoc')->notice('downloadDocument: @id', ['@id' => $documentId]);
    try {
      $response = $this->httpClient->get($this->apiBase . '/documents/' . $documentId . '/download', [
        'headers' => [
          'Authorization' => 'API-Key ' . $this->apiKey,
        ],
      ]);

      return (string) $response->getBody();
    }
    catch (RequestException $e) {
      \Drupal::logger('pandadoc')->error('downloadDocument error: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Register a webhook subscription for document events.
   *
   * @param string $url      The callback URL PandaDoc will POST to.
   * @param array  $triggers Event types to subscribe to.
   *
   * @return array Webhook subscription details.
   */
  public function registerWebhook(string $url, array $triggers = []): array {
    \Drupal::logger('pandadoc')->notice('registerWebhook: @url', ['@url' => $url]);
    try {
      if (empty($triggers)) {
        $triggers = [
          'document_state_changed',
          'document_completed',
          'document_creation_failed',
        ];
      }

      $response = $this->httpClient->post($this->apiBase . '/webhook-subscriptions', [
        'headers' => array_merge($this->authHeaders(), [
          'Content-Type' => 'application/json',
        ]),
        'json' => [
          'url' => $url,
          'triggers' => $triggers,
        ],
      ]);

      return json_decode($response->getBody(), TRUE);
    }
    catch (RequestException $e) {
      \Drupal::logger('pandadoc')->error('registerWebhook error: @msg', ['@msg' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * List existing webhook subscriptions.
   *
   * @return array List of webhook subscriptions.
   */
  public function listWebhooks(): array {
    \Drupal::logger('pandadoc')->notice('listWebhooks');
    try {
      $response = $this->httpClient->get($this->apiBase . '/webhook-subscriptions', [
        'headers' => $this->authHeaders(),
      ]);

      return json_decode($response->getBody(), TRUE);
    }
    catch (RequestException $e) {
      \Drupal::logger('pandadoc')->error('listWebhooks error: @msg', ['@msg' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

}
