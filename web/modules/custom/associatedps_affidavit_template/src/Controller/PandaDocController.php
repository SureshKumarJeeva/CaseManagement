<?php

namespace Drupal\associatedps_affidavit_template\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PandaDocController extends ControllerBase {

  /**
   * Upload the Library node's file to PandaDoc as a template,
   * create an editing session, then redirect to the editor page.
   *
   * Route: /admin/content/pandadoc/upload/{nid}
   */
  public function uploadToPandaDoc($nid) {
    $libraryNode = Node::load($nid);
    if (!$libraryNode || $libraryNode->bundle() !== 'library') {
      $this->messenger()->addError($this->t('Invalid Library node.'));
      return new RedirectResponse('/node/' . $nid . '/edit');
    }

    // Get the uploaded file from field_import_template.
    $fileField = $libraryNode->get('field_import_template')->getValue();
    if (empty($fileField)) {
      $this->messenger()->addError($this->t('No file uploaded in Import Template field.'));
      return new RedirectResponse('/node/' . $nid . '/edit');
    }

    $fid = $fileField[0]['target_id'];
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
    if (!$file) {
      $this->messenger()->addError($this->t('File not found.'));
      return new RedirectResponse('/node/' . $nid . '/edit');
    }

    $filePath = \Drupal::service('file_system')->realpath($file->getFileUri());
    $fileName = $file->getFilename();
    $templateName = $libraryNode->getTitle() ?: $fileName;

    /** @var \Drupal\associatedps_affidavit_template\Service\PandaDocClient $pandaDocClient */
    $pandaDocClient = \Drupal::service('associatedps_affidavit_template.pandadoc_client');

    // Auto-register webhook on first use.
    $this->ensureWebhookRegistered($pandaDocClient);

    // Upload file as PandaDoc template.
    $category = $libraryNode->field_category->value ?? 'Affidavit';
    $uploadResult = $pandaDocClient->uploadTemplate($filePath, $templateName, [$category], $fileName);
    if (isset($uploadResult['error'])) {
      $this->messenger()->addError($this->t('PandaDoc upload failed: @error', ['@error' => $uploadResult['error']]));
      return new RedirectResponse('/node/' . $nid . '/edit');
    }

    $pandadocTemplateId = $uploadResult['id'] ?? '';
    if (empty($pandadocTemplateId)) {
      $this->messenger()->addError($this->t('PandaDoc did not return a template ID.'));
      return new RedirectResponse('/node/' . $nid . '/edit');
    }

    \Drupal::logger('pandadoc')->notice('Template uploaded: @id', ['@id' => $pandadocTemplateId]);

    // Store template ID in tracking table.
    \Drupal::database()->insert('pandadoc_documents')
      ->fields([
        'pandadoc_template_id' => $pandadocTemplateId,
        'library_nid' => $nid,
        'status' => 'template_uploaded',
        'created' => time(),
        'updated' => time(),
      ])
      ->execute();

    // Poll template status until it reaches template.PROCESSED.
    $maxAttempts = 15;
    $delaySeconds = 2;
    $processed = FALSE;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
      sleep($delaySeconds);
      $details = $pandaDocClient->getTemplateStatus($pandadocTemplateId);

      if (isset($details['error'])) {
        \Drupal::logger('pandadoc')->warning('Template status check #@n failed: @err', [
          '@n' => $attempt,
          '@err' => $details['error'],
        ]);
        continue;
      }

      $templateStatus = $details['status'] ?? '';
      \Drupal::logger('pandadoc')->notice('Template status check #@n: @status', [
        '@n' => $attempt,
        '@status' => $templateStatus,
      ]);

      if ($templateStatus === 'template.PROCESSED') {
        $processed = TRUE;
        break;
      }
    }

    if (!$processed) {
      $this->messenger()->addWarning($this->t('Template uploaded to PandaDoc (ID: @id) but is still processing. Please try editing it later.', ['@id' => $pandadocTemplateId]));
      return new RedirectResponse('/node/' . $nid . '/edit?pandadoc_template_id=' . $pandadocTemplateId);
    }

    // Create editing session to get E-Token.
    $sessionResult = $pandaDocClient->createEditingSession($pandadocTemplateId);

    if (isset($sessionResult['error'])) {
      // Editing session failed but template was created — store ID and let user know.
      $this->messenger()->addWarning($this->t('Template uploaded to PandaDoc (ID: @id) but editor session failed. You can use the template ID directly.', ['@id' => $pandadocTemplateId]));
      return new RedirectResponse('/node/' . $nid . '/edit?pandadoc_template_id=' . $pandadocTemplateId);
    }

    // Redirect to editor page with E-Token.
    return new RedirectResponse('/admin/content/pandadoc/editor/' . $pandadocTemplateId . '/' . $nid . '?session_id=' . ($sessionResult['token'] ?? ''));
  }

  /**
   * Render the PandaDoc editor page with iframe.
   *
   * Route: /admin/content/pandadoc/editor/{pandadocTemplateId}/{nid}
   */
  public function editorPage($pandadocTemplateId, $nid, Request $request) {
    $sessionId = $request->query->get('session_id', '');

    // Dynamically build variable names from Job content type fields.
    /** @var \Drupal\associatedps_affidavit_template\Service\PandaDocTokenMapper $tokenMapper */
    $tokenMapper = \Drupal::service('associatedps_affidavit_template.pandadoc_token_mapper');
    $variables = $tokenMapper->getJobVariableNames();

    $category = $request->query->get('category', '');
    $callbackUrl = '/admin/content/pandadoc/editor-callback?nid=' . $nid . '&pandadoc_template_id=' . $pandadocTemplateId . '&category=' . urlencode($category);

    $build = [
      '#theme' => 'pandadoc_editor',
      '#pandadoc_template_id' => $pandadocTemplateId,
      '#session_id' => $sessionId,
      '#nid' => $nid,
      '#callback_url' => $callbackUrl,
      '#attached' => [
        'library' => [
          'associatedps_affidavit_template/pandadoc-editor',
        ],
        'drupalSettings' => [
          'pandadocEditor' => [
            'templateId' => $pandadocTemplateId,
            'sessionId' => $sessionId,
            'nid' => $nid,
            'callbackUrl' => $callbackUrl,
            'variables' => $variables,
          ],
        ],
      ],
    ];

    // If no theme template exists, render inline markup.
    if (empty($build['#theme'])) {
      unset($build['#theme']);
    }

    $build['#markup'] = '<div id="pandadoc-editor-wrapper">'
      . '<h2>' . $this->t('PandaDoc Template Editor') . '</h2>'
      . '<p>' . $this->t('Position your placeholders in the document below. When done, click "Done Editing - Return to APS" button.') . '</p>'
      . '<div id="pandadoc-editor-container" '
      . 'data-template-id="' . htmlspecialchars($pandadocTemplateId) . '" '
      . 'data-session-id="' . htmlspecialchars($sessionId) . '" '
      . 'data-callback-url="' . htmlspecialchars($callbackUrl) . '" '
      . 'data-variables="' . htmlspecialchars(json_encode($variables)) . '">'
      . '</div>'
      . '<div id="pandadoc-editor-status" class="messages messages--status" style="display:none;"></div>'
      . '</div>';

    return $build;
  }

  /**
   * Callback after user saves template in PandaDoc editor.
   * Redirects back to Library node edit page with template ID in query string.
   *
   * Route: /admin/content/pandadoc/editor-callback
   */
  public function editorCallback(Request $request) {
    $nid = $request->query->get('nid', '');
    $pandadocTemplateId = $request->query->get('pandadoc_template_id', '');

    if (!empty($pandadocTemplateId)) {
      // Update tracking table status.
      \Drupal::database()->update('pandadoc_documents')
        ->fields([
          'status' => 'template_ready',
          'updated' => time(),
        ])
        ->condition('pandadoc_template_id', $pandadocTemplateId)
        ->execute();

      if (!empty($nid) && $nid !== '0') {
        // Existing node — save template ID and redirect to edit form.
        $libraryNode = Node::load($nid);
        if ($libraryNode && $libraryNode->hasField('field_pandadoc_template_id')) {
          $libraryNode->set('field_pandadoc_template_id', $pandadocTemplateId);
          $libraryNode->save();
        }

        $this->messenger()->addStatus($this->t('PandaDoc template saved successfully. Template ID: @id', ['@id' => $pandadocTemplateId]));
        return new RedirectResponse('/node/' . $nid . '/edit?pandadoc_template_id=' . $pandadocTemplateId);
      }

      // New node (nid=0) — redirect to add form with template ID and category pre-populated.
      $category = $request->query->get('category', '');
      $this->messenger()->addStatus($this->t('PandaDoc template created. Template ID: @id. Please save the Library item.', ['@id' => $pandadocTemplateId]));
      return new RedirectResponse('/node/add/library?pandadoc_template_id=' . $pandadocTemplateId . '&category=' . urlencode($category));
    }

    return new RedirectResponse('/admin/content');
  }

  /**
   * Generate an Affidavit document via PandaDoc for a given Job.
   *
   * Route: /pandadoc/generate-affidavit/{jobId}
   */
  public function generateAffidavit($jobId) {
    return $this->generateViaPandaDoc($jobId, 'Affidavit');
  }

  /**
   * Generate a Correspondence document via PandaDoc for a given Job.
   *
   * Route: /pandadoc/generate-correspondence/{jobId}
   */
  public function generateCorrespondence($jobId) {
    return $this->generateViaPandaDoc($jobId, 'Correspondence');
  }

  /**
   * Core generation logic shared by generateAffidavit and generateCorrespondence.
   *
   * Flow: Find Job's documents → find linked Library template with PandaDoc ID
   * → resolve tokens → create PandaDoc document → poll status → return download info.
   */
  protected function generateViaPandaDoc(int $jobId, string $category): JsonResponse {
    $jobNode = Node::load($jobId);
    if (!$jobNode || $jobNode->bundle() !== 'job') {
      return new JsonResponse(['error' => 'Invalid Job node.'], 400);
    }

    $libraryNode = NULL;
    $pandadocTemplateId = '';

    // Check if a library_nid was passed (user selected a template from the modal).
    $request = \Drupal::request();
    $manualLibraryNid = $request->query->get('library_nid');

    if (!empty($manualLibraryNid)) {
      // User manually selected a template from the popup.
      $candidateLibrary = Node::load($manualLibraryNid);
      if ($candidateLibrary && $candidateLibrary->bundle() === 'library'
        && $candidateLibrary->hasField('field_pandadoc_template_id')
        && !empty($candidateLibrary->field_pandadoc_template_id->value)) {
        $libraryNode = $candidateLibrary;
        $pandadocTemplateId = $candidateLibrary->field_pandadoc_template_id->value;
      }
    }

    if (empty($pandadocTemplateId)) {
      // Traverse: Job → field_documents (paragraphs) → field_document (Document nodes)
      // → field_affidavit_template (Library node) → check category + pandadoc_template_id.
      foreach ($jobNode->get('field_documents') as $docGroup) {
        $paragraph = $docGroup->entity;
        if (!$paragraph) {
          continue;
        }
        foreach ($paragraph->get('field_document') as $docItem) {
          $docNode = $docItem->entity;
          if (!$docNode) {
            continue;
          }
          $templateTargetId = $docNode->field_affidavit_template->target_id ?? NULL;
          if (empty($templateTargetId)) {
            continue;
          }
          $candidateLibrary = Node::load($templateTargetId);
          if (!$candidateLibrary || $candidateLibrary->bundle() !== 'library') {
            continue;
          }
          if ($candidateLibrary->field_category->value !== $category) {
            continue;
          }
          if (!$candidateLibrary->hasField('field_pandadoc_template_id') || empty($candidateLibrary->field_pandadoc_template_id->value)) {
            continue;
          }

          $libraryNode = $candidateLibrary;
          $pandadocTemplateId = $candidateLibrary->field_pandadoc_template_id->value;
          break 2;
        }
      }
    }

    if (empty($pandadocTemplateId) || !$libraryNode) {
      return new JsonResponse([
        'no_template' => TRUE,
        'category' => $category,
        'job_id' => $jobId,
        'message' => "No {$category} template linked to this job's documents. Please select a template.",
      ], 200);
    }

    /** @var \Drupal\associatedps_affidavit_template\Service\PandaDocClient $pandaDocClient */
    $pandaDocClient = \Drupal::service('associatedps_affidavit_template.pandadoc_client');

    /** @var \Drupal\associatedps_affidavit_template\Service\PandaDocTokenMapper $tokenMapper */
    $tokenMapper = \Drupal::service('associatedps_affidavit_template.pandadoc_token_mapper');

    // Resolve token values from Job data.
    $tokens = $tokenMapper->resolveTokensForJob($jobNode);

    \Drupal::logger('pandadoc')->notice('Resolved tokens for Job @jid: @tokens', [
      '@jid' => $jobId,
      '@tokens' => json_encode($tokens),
    ]);

    // Create document from template with resolved tokens.
    $docName = $jobNode->getTitle() . ' - ' . $category . ' - ' . date('Y-m-d H:i');
    $createResult = $pandaDocClient->createDocument($pandadocTemplateId, $docName, $tokens, [
      'job_nid' => $jobId,
      'library_nid' => $libraryNode->id(),
    ]);

    if (isset($createResult['error'])) {
      return new JsonResponse(['error' => 'PandaDoc document creation failed: ' . $createResult['error']], 500);
    }

    $documentId = $createResult['id'] ?? '';
    if (empty($documentId)) {
      return new JsonResponse(['error' => 'PandaDoc did not return a document ID.'], 500);
    }

    // Track in database.
    \Drupal::database()->insert('pandadoc_documents')
      ->fields([
        'pandadoc_document_id' => $documentId,
        'pandadoc_template_id' => $pandadocTemplateId,
        'job_nid' => $jobId,
        'library_nid' => $libraryNode->id(),
        'status' => 'document.uploaded',
        'created' => time(),
        'updated' => time(),
      ])
      ->execute();

    return new JsonResponse([
      'success' => TRUE,
      'document_id' => $documentId,
      'status_url' => '/pandadoc/status/' . $documentId,
      'download_url' => '/pandadoc/download/' . $documentId,
      'editor_url' => '/pandadoc/document-editor/' . $documentId . '?job_id=' . $jobId,
      'message' => "{$category} generation started. Polling for completion...",
    ]);
  }

  /**
   * Check PandaDoc document generation status.
   *
   * Route: /pandadoc/status/{documentId}
   */
  public function checkStatus($documentId) {
    /** @var \Drupal\associatedps_affidavit_template\Service\PandaDocClient $pandaDocClient */
    $pandaDocClient = \Drupal::service('associatedps_affidavit_template.pandadoc_client');

    $statusResult = $pandaDocClient->getDocumentStatus($documentId);

    if (isset($statusResult['error'])) {
      return new JsonResponse(['error' => $statusResult['error']], 500);
    }

    $status = $statusResult['status'] ?? 'unknown';

    // Update tracking table.
    \Drupal::database()->update('pandadoc_documents')
      ->fields([
        'status' => $status,
        'updated' => time(),
      ])
      ->condition('pandadoc_document_id', $documentId)
      ->execute();

    $downloadReady = in_array($status, ['document.draft', 'document.completed', 'document.sent', 'document.viewed']);

    return new JsonResponse([
      'document_id' => $documentId,
      'status' => $status,
      'download_ready' => $downloadReady,
      'download_url' => $downloadReady ? '/pandadoc/download/' . $documentId : NULL,
    ]);
  }

  /**
   * Download generated PDF from PandaDoc and serve to browser.
   *
   * Route: /pandadoc/download/{documentId}
   */
  public function downloadPdf($documentId) {
    /** @var \Drupal\associatedps_affidavit_template\Service\PandaDocClient $pandaDocClient */
    $pandaDocClient = \Drupal::service('associatedps_affidavit_template.pandadoc_client');

    $pdfContent = $pandaDocClient->downloadDocument($documentId);

    if (empty($pdfContent)) {
      return new JsonResponse(['error' => 'Failed to download PDF from PandaDoc.'], 500);
    }

    // Return PDF for inline display (opens in new tab).
    $response = new Response($pdfContent);
    $response->headers->set('Content-Type', 'application/pdf');
    $response->headers->set('Content-Disposition', 'inline; filename="document_' . $documentId . '.pdf"');
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

    return $response;
  }

  /**
   * Render the PandaDoc document editor page with embedded iframe.
   * Allows the user to edit the generated document before downloading.
   *
   * Route: /pandadoc/document-editor/{documentId}
   */
  public function documentEditorPage($documentId, Request $request) {
    $jobId = $request->query->get('job_id', '');

    /** @var \Drupal\associatedps_affidavit_template\Service\PandaDocClient $pandaDocClient */
    $pandaDocClient = \Drupal::service('associatedps_affidavit_template.pandadoc_client');

    // Document must be in "sent" status before a session can be created.
    // Send it silently (no email to recipients) first.
    $docStatus = $pandaDocClient->getDocumentStatus($documentId);
    $currentStatus = $docStatus['status'] ?? '';

    if ($currentStatus === 'document.draft') {
      $sendResult = $pandaDocClient->sendDocument($documentId, TRUE);
      if (isset($sendResult['error'])) {
        \Drupal::logger('pandadoc')->warning('Silent send failed for @id: @err', [
          '@id' => $documentId,
          '@err' => $sendResult['error'],
        ]);
      }
      else {
        // Wait briefly for status to transition to "document.sent".
        $maxWait = 10;
        for ($i = 0; $i < $maxWait; $i++) {
          sleep(1);
          $docStatus = $pandaDocClient->getDocumentStatus($documentId);
          $currentStatus = $docStatus['status'] ?? '';
          if ($currentStatus !== 'document.draft') {
            break;
          }
        }
      }
    }

    // Create a document session for iframe embedding.
    $sessionResult = $pandaDocClient->createDocumentSession($documentId);
    $sessionId = $sessionResult['id'] ?? '';

    if (isset($sessionResult['error']) || empty($sessionId)) {
      \Drupal::logger('pandadoc')->error('Document session failed for @id: @err', [
        '@id' => $documentId,
        '@err' => $sessionResult['error'] ?? 'empty session ID',
      ]);
      $this->messenger()->addError($this->t('Failed to create document editing session. You can still download the document.'));
    }

    $build = [
      'content' => [
        '#type' => 'inline_template',
        '#template' => '<div id="pandadoc-document-editor-wrapper">'
          . '<h2>{{ title }}</h2>'
          . '<p>{{ description }}</p>'
          . '<div id="pandadoc-document-editor-status" class="messages messages--status" style="display:none;"></div>'
          . '<div id="pandadoc-document-editor-actions" style="margin-bottom:10px;">'
          . '<button type="button" id="pandadoc-download-btn" class="button button--primary" data-document-id="{{ document_id }}">Download PDF</button> '
          . '<button type="button" id="pandadoc-back-btn" class="button">Back to Job</button>'
          . '</div>'
          . '<div id="pandadoc-document-editor-container" '
          . 'data-document-id="{{ document_id }}" '
          . 'data-session-id="{{ session_id }}" '
          . 'data-job-id="{{ job_id }}" '
          . 'data-download-url="{{ download_url }}"'
          . '></div>'
          . '</div>',
        '#context' => [
          'title' => $this->t('PandaDoc Document Editor'),
          'description' => $this->t('Review and edit the generated document below. Use the buttons to download or save when done.'),
          'document_id' => $documentId,
          'session_id' => $sessionId,
          'job_id' => $jobId,
          'download_url' => '/pandadoc/download/' . $documentId,
        ],
      ],
      '#attached' => [
        'library' => [
          'associatedps_affidavit_template/pandadoc-document-editor',
        ],
        'drupalSettings' => [
          'pandadocDocumentEditor' => [
            'documentId' => $documentId,
            'sessionId' => $sessionId,
            'jobId' => $jobId,
            'downloadUrl' => '/pandadoc/download/' . $documentId,
          ],
        ],
      ],
    ];

    return $build;
  }

  /**
   * AJAX endpoint: create a new document session for refreshing expired sessions.
   *
   * Route: /pandadoc/document-session/{documentId} (POST)
   */
  public function createDocumentSessionAjax($documentId) {
    /** @var \Drupal\associatedps_affidavit_template\Service\PandaDocClient $pandaDocClient */
    $pandaDocClient = \Drupal::service('associatedps_affidavit_template.pandadoc_client');

    // Ensure document is sent before creating session.
    $docStatus = $pandaDocClient->getDocumentStatus($documentId);
    $currentStatus = $docStatus['status'] ?? '';
    if ($currentStatus === 'document.draft') {
      $pandaDocClient->sendDocument($documentId, TRUE);
      sleep(2);
    }

    $sessionResult = $pandaDocClient->createDocumentSession($documentId);
    if (isset($sessionResult['error'])) {
      return new JsonResponse(['error' => 'Failed to create document session: ' . $sessionResult['error']], 500);
    }

    return new JsonResponse([
      'session_id' => $sessionResult['id'] ?? '',
    ]);
  }

  /**
   * List Library nodes that have PandaDoc template IDs.
   * Used when associating templates to documents at Document add/edit page.
   *
   * Route: /admin/content/pandadoc-templates
   */
  public function listPandaDocTemplates() {
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'library')
      ->condition('field_pandadoc_template_id', '', '<>')
      ->accessCheck(TRUE)
      ->sort('title', 'ASC');

    $nids = $query->execute();
    $rows = [];
    $header = ['Title', 'Category', 'PandaDoc Template ID'];

    if ($nids) {
      $nodes = Node::loadMultiple($nids);
      foreach ($nodes as $node) {
        $rows[] = [
          $node->getTitle(),
          $node->field_category->value ?? '',
          $node->field_pandadoc_template_id->value ?? '',
        ];
      }
    }

    return [
      'template_list' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No PandaDoc templates found. Create a Library item with category Affidavit or Correspondence and upload a file to PandaDoc.'),
      ],
    ];
  }

  /**
   * AJAX endpoint: upload a file directly to PandaDoc (no node save required).
   * Returns JSON with templateId and sessionToken for the editor.
   *
   * Route: /admin/content/pandadoc/ajax-upload (POST, multipart/form-data)
   */
  public function ajaxUploadToPandaDoc(Request $request) {
    $file = $request->files->get('file');
    if (!$file) {
      return new JsonResponse(['error' => 'No file provided.'], 400);
    }

    $category = $request->request->get('category', 'Affidavit');
    $nid = $request->request->get('nid', '0');
    $filePath = $file->getRealPath();
    $fileName = $file->getClientOriginalName();
    $templateName = pathinfo($fileName, PATHINFO_FILENAME);

    /** @var \Drupal\associatedps_affidavit_template\Service\PandaDocClient $pandaDocClient */
    $pandaDocClient = \Drupal::service('associatedps_affidavit_template.pandadoc_client');

    $this->ensureWebhookRegistered($pandaDocClient);

    // Upload file as PandaDoc template.
    $uploadResult = $pandaDocClient->uploadTemplate($filePath, $templateName, [$category], $fileName);
    if (isset($uploadResult['error'])) {
      return new JsonResponse(['error' => 'PandaDoc upload failed: ' . $uploadResult['error']], 500);
    }

    $pandadocTemplateId = $uploadResult['id'] ?? '';
    if (empty($pandadocTemplateId)) {
      return new JsonResponse(['error' => 'PandaDoc did not return a template ID.'], 500);
    }

    \Drupal::logger('pandadoc')->notice('AJAX template uploaded: @id', ['@id' => $pandadocTemplateId]);

    // Store in tracking table.
    \Drupal::database()->insert('pandadoc_documents')
      ->fields([
        'pandadoc_template_id' => $pandadocTemplateId,
        'library_nid' => $nid,
        'status' => 'template_uploaded',
        'created' => time(),
        'updated' => time(),
      ])
      ->execute();

    // Poll template status until template.PROCESSED.
    $maxAttempts = 15;
    $delaySeconds = 2;
    $processed = FALSE;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
      sleep($delaySeconds);
      $details = $pandaDocClient->getTemplateStatus($pandadocTemplateId);

      if (isset($details['error'])) {
        \Drupal::logger('pandadoc')->warning('AJAX status check #@n failed: @err', [
          '@n' => $attempt,
          '@err' => $details['error'],
        ]);
        continue;
      }

      $templateStatus = $details['status'] ?? '';
      if ($templateStatus === 'template.PROCESSED') {
        $processed = TRUE;
        break;
      }
    }

    if (!$processed) {
      return new JsonResponse([
        'error' => 'Template uploaded (ID: ' . $pandadocTemplateId . ') but still processing. Try again later.',
        'templateId' => $pandadocTemplateId,
      ], 202);
    }

    // Create editing session.
    $sessionResult = $pandaDocClient->createEditingSession($pandadocTemplateId);
    if (isset($sessionResult['error'])) {
      return new JsonResponse([
        'error' => 'Template uploaded but editor session failed: ' . $sessionResult['error'],
        'templateId' => $pandadocTemplateId,
      ], 500);
    }

    return new JsonResponse([
      'templateId' => $pandadocTemplateId,
      'sessionToken' => $sessionResult['token'] ?? '',
    ]);
  }

  /**
   * AJAX endpoint: create a new editing session for an existing template.
   * Used by the "Update at PandaDoc" button.
   *
   * Route: /admin/content/pandadoc/ajax-session (POST, JSON)
   */
  public function ajaxCreateEditingSession(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    $templateId = $data['templateId'] ?? '';

    if (empty($templateId)) {
      return new JsonResponse(['error' => 'No templateId provided.'], 400);
    }

    /** @var \Drupal\associatedps_affidavit_template\Service\PandaDocClient $pandaDocClient */
    $pandaDocClient = \Drupal::service('associatedps_affidavit_template.pandadoc_client');

    $sessionResult = $pandaDocClient->createEditingSession($templateId);
    if (isset($sessionResult['error'])) {
      return new JsonResponse(['error' => 'Failed to create editing session: ' . $sessionResult['error']], 500);
    }

    return new JsonResponse([
      'sessionToken' => $sessionResult['token'] ?? '',
    ]);
  }

  /**
   * AJAX endpoint: list available templates filtered by category.
   * Used by the template selection modal when a Job has no linked documents.
   *
   * Route: /pandadoc/templates-by-category/{category}
   */
  public function listTemplatesByCategory($category) {
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'library')
      ->condition('field_pandadoc_template_id', '', '<>')
      ->condition('field_category', $category)
      ->accessCheck(TRUE)
      ->sort('title', 'ASC');

    $nids = $query->execute();
    $templates = [];

    if ($nids) {
      $nodes = Node::loadMultiple($nids);
      foreach ($nodes as $node) {
        $templates[] = [
          'nid' => $node->id(),
          'title' => $node->getTitle(),
          'category' => $node->field_category->value ?? '',
        ];
      }
    }

    return new JsonResponse(['templates' => $templates]);
  }

  /**
   * AJAX endpoint: return all PandaDoc-style token names for the email token picker.
   *
   * Route: /pandadoc/email-token-list
   */
  public function listEmailTokens() {
    /** @var \Drupal\associatedps_affidavit_template\Service\PandaDocTokenMapper $tokenMapper */
    $tokenMapper = \Drupal::service('associatedps_affidavit_template.pandadoc_token_mapper');
    $variableNames = $tokenMapper->getJobVariableNames();

    return new JsonResponse(['tokens' => $variableNames]);
  }

  /**
   * AJAX endpoint: list all email templates from Library content type.
   * Used by the Generate Email modal on the Job edit page.
   *
   * Route: /pandadoc/email-templates
   */
  public function listEmailTemplates() {
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'library')
      ->condition('field_category', 'Email')
      ->accessCheck(TRUE)
      ->sort('title', 'ASC');

    $nids = $query->execute();
    $templates = [];

    if ($nids) {
      $nodes = Node::loadMultiple($nids);
      foreach ($nodes as $node) {
        $templates[] = [
          'nid' => $node->id(),
          'title' => $node->getTitle(),
        ];
      }
    }

    return new JsonResponse(['templates' => $templates]);
  }

  /**
   * AJAX endpoint: resolve an email template's subject and body with Job data.
   * Returns the resolved subject, body (plain text), and client email.
   *
   * Route: /pandadoc/resolve-email-template/{templateNid}/{jobId}
   */
  public function resolveEmailTemplate($templateNid, $jobId) {
    $jobNode = Node::load($jobId);
    if (!$jobNode || $jobNode->bundle() !== 'job') {
      return new JsonResponse(['error' => 'Invalid Job node.'], 400);
    }

    $templateNode = Node::load($templateNid);
    if (!$templateNode || $templateNode->bundle() !== 'library') {
      return new JsonResponse(['error' => 'Invalid template node.'], 400);
    }

    // Get subject and body from the email template.
    $subject = $templateNode->field_email_subject->value ?? '';
    $body = $templateNode->body->value ?? '';

    // Resolve PandaDoc-style tokens using PandaDocTokenMapper.
    /** @var \Drupal\associatedps_affidavit_template\Service\PandaDocTokenMapper $tokenMapper */
    $tokenMapper = \Drupal::service('associatedps_affidavit_template.pandadoc_token_mapper');
    $resolvedTokens = $tokenMapper->resolveTokensForJob($jobNode);

    // Replace {{Token.Name}} patterns in subject and body.
    foreach ($resolvedTokens as $token) {
      $placeholder = '{{' . $token['name'] . '}}';
      $subject = str_replace($placeholder, $token['value'], $subject);
      $body = str_replace($placeholder, $token['value'], $body);
    }

    // Convert HTML to plain text: decode entities like &nbsp; then strip tags.
    $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Remove non-breaking spaces left over from HTML formatting.
    $body = str_replace("\xC2\xA0", ' ', $body);
    $body = trim($body);

    // Get client email.
    $email = '';
    if (!empty($jobNode->field_client_s_job_email->value)) {
      $email = $jobNode->field_client_s_job_email->value;
    }
    elseif (!empty($jobNode->field_client->target_id)) {
      $clientNode = Node::load($jobNode->field_client->target_id);
      if ($clientNode && !empty($clientNode->field_email->value)) {
        $email = $clientNode->field_email->value;
      }
    }

    return new JsonResponse([
      'subject' => $subject,
      'body' => $body,
      'email' => $email,
    ]);
  }

  /**
   * Ensure webhook is registered with PandaDoc. Called automatically on first upload.
   */
  protected function ensureWebhookRegistered($pandaDocClient): void {
    $webhooks = $pandaDocClient->listWebhooks();

    // Check if our webhook URL is already registered.
    $baseUrl = \Drupal::request()->getSchemeAndHttpHost();
    $webhookUrl = $baseUrl . '/pandadoc/webhook';

    if (!isset($webhooks['error'])) {
      $results = $webhooks['results'] ?? $webhooks;
      if (is_array($results)) {
        foreach ($results as $webhook) {
          if (isset($webhook['url']) && $webhook['url'] === $webhookUrl) {
            return; // Already registered.
          }
        }
      }
    }

    // Register webhook.
    $result = $pandaDocClient->registerWebhook($webhookUrl);
    if (isset($result['error'])) {
      \Drupal::logger('pandadoc')->warning('Webhook registration failed: @error', ['@error' => $result['error']]);
    }
    else {
      \Drupal::logger('pandadoc')->notice('Webhook registered: @url', ['@url' => $webhookUrl]);
    }
  }

}
