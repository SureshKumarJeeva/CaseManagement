<?php

namespace Drupal\associatedps_affidavit_template\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * DocuSign integration controller — M5 surface (upload + editor).
 *
 * M6/M7 (envelope generation, sender view, download) will append methods
 * to this controller. Mirrors PandaDocController structurally; PandaDoc
 * code stays in place but is dormant.
 */
class DocuSignController extends ControllerBase {

  /**
   * Upload the Library node's file to DocuSign as a template, then redirect
   * the user to the embedded edit-view iframe.
   *
   * Route: /admin/content/docusign/upload/{nid}
   */
  public function uploadToDocuSign($nid) {
    $libraryNode = Node::load($nid);
    if (!$libraryNode || $libraryNode->bundle() !== 'library') {
      $this->messenger()->addError($this->t('Invalid Library node.'));
      return new RedirectResponse('/node/' . $nid . '/edit');
    }

    $fileField = $libraryNode->get('field_import_template')->getValue();
    if (empty($fileField)) {
      $this->messenger()->addError($this->t('No file uploaded in Import Template field.'));
      return new RedirectResponse('/node/' . $nid . '/edit');
    }

    $file = \Drupal::entityTypeManager()->getStorage('file')->load($fileField[0]['target_id']);
    if (!$file) {
      $this->messenger()->addError($this->t('File not found.'));
      return new RedirectResponse('/node/' . $nid . '/edit');
    }

    $filePath = \Drupal::service('file_system')->realpath($file->getFileUri());
    $fileName = $file->getFilename();
    $templateName = $libraryNode->getTitle() ?: $fileName;
    $category = $libraryNode->field_category->value ?? 'Affidavit';

    /** @var \Drupal\associatedps_affidavit_template\Service\DocuSignClient $client */
    $client = \Drupal::service('associatedps_affidavit_template.docusign_client');

    $uploadResult = $client->uploadTemplate(
      $filePath,
      $templateName,
      pathinfo($fileName, PATHINFO_EXTENSION),
      $fileName
    );
    if (isset($uploadResult['error'])) {
      $this->messenger()->addError($this->t('DocuSign upload failed: @error', ['@error' => $uploadResult['error']]));
      return new RedirectResponse('/node/' . $nid . '/edit');
    }

    $templateId = $uploadResult['templateId'];
    \Drupal::logger('docusign')->notice('Template uploaded: @id', ['@id' => $templateId]);

    \Drupal::database()->insert('docusign_envelopes')
      ->fields([
        'template_id' => $templateId,
        'account_id' => $this->resolveAccountId($client),
        'library_nid' => $nid,
        'status' => 'template_uploaded',
        'created' => time(),
        'updated' => time(),
      ])
      ->execute();

    // returnUrl points to the public bounce endpoint, NOT to editor-callback.
    // DocuSign's iframe redirects here on Save/Cancel; the bounce page
    // postMessages the parent admin page which then top-level-navigates
    // to editor-callback (carrying the session cookie). See bounce + signing
    // callback methods for the SameSite cookie rationale.
    $returnUrl = \Drupal::request()->getSchemeAndHttpHost()
      . '/docusign/template-editor-bounce'
      . '?nid=' . $nid
      . '&docusign_template_id=' . urlencode($templateId)
      . '&category=' . urlencode($category);
    $viewResult = $client->createTemplateEditView($templateId, $returnUrl);
    if (isset($viewResult['error'])) {
      $this->messenger()->addWarning($this->t('Template uploaded (ID: @id) but editor view failed: @err', [
        '@id' => $templateId,
        '@err' => $viewResult['error'],
      ]));
      return new RedirectResponse('/node/' . $nid . '/edit?docusign_template_id=' . $templateId);
    }

    return new RedirectResponse('/admin/content/docusign/editor/' . $templateId . '/' . $nid
      . '?edit_view_url=' . urlencode($viewResult['url'])
      . '&category=' . urlencode($category));
  }

  /**
   * Render the DocuSign template editor page (iframe wrapper).
   *
   * Route: /admin/content/docusign/editor/{docusignTemplateId}/{nid}
   */
  public function editorPage($docusignTemplateId, $nid, Request $request) {
    $editViewUrl = $request->query->get('edit_view_url', '');
    $category = $request->query->get('category', '');

    /** @var \Drupal\associatedps_affidavit_template\Service\DocuSignTokenMapper $mapper */
    $mapper = \Drupal::service('associatedps_affidavit_template.docusign_token_mapper');
    // [{label, value}] — label is the field's Tab-Section-Field path from the
    // Job form display, value is the token the author actually pastes.
    $variables = $mapper->getJobVariableOptions();

    $callbackUrl = '/admin/content/docusign/editor-callback'
      . '?nid=' . $nid
      . '&docusign_template_id=' . urlencode($docusignTemplateId)
      . '&category=' . urlencode($category);

    $build = [
      'content' => [
        '#type' => 'inline_template',
        '#template' => '<div id="docusign-editor-wrapper">'
          . '<h2>{{ title }}</h2>'
          . '<p>{{ description|raw }}</p>'
          . '<div id="docusign-editor-status" class="messages messages--status" style="display:none;"></div>'
          . '<div id="docusign-token-picker-container"></div>'
          . '<div id="docusign-editor-actions" style="margin-bottom:10px;">'
          . '  <a href="{{ callback_url }}" class="button button--primary">Done Editing — Return to APS</a>'
          . '</div>'
          . '<iframe id="docusign-editor-iframe" '
          . '        src="{{ edit_view_url }}" '
          . '        data-template-id="{{ docusign_template_id }}" '
          . '        data-callback-url="{{ callback_url }}" '
          . '        allow="clipboard-read; clipboard-write" '
          . '        style="width:100%; height:calc(100vh - 160px); min-height:720px; border:1px solid #ccc;">'
          . '</iframe>'
          . '</div>',
        '#context' => [
          'title' => $this->t('DocuSign Template Editor'),
          'description' => $this->t('Drag a <strong>Text</strong> (or <strong>Date</strong>) tab onto the document — <strong>NOT a Prefill Text tab</strong>. Make sure each tab is assigned to <strong>Signer 1</strong> in the recipient panel on the right. Set its <strong>Data Label</strong> (under Properties) to one of the token labels above. <em>Why this matters:</em> Text tabs assigned to a recipient stay editable in the document preview before download, so you can tweak prefilled values per-job. Prefill Text tabs are locked once generated and cannot be edited in the preview.'),
          'edit_view_url' => $editViewUrl,
          'docusign_template_id' => $docusignTemplateId,
          'callback_url' => $callbackUrl,
        ],
      ],
      '#attached' => [
        'library' => ['associatedps_affidavit_template/docusign-editor'],
        'drupalSettings' => [
          'docusignEditor' => [
            'templateId' => $docusignTemplateId,
            'editViewUrl' => $editViewUrl,
            'nid' => $nid,
            'callbackUrl' => $callbackUrl,
            'refreshUrl' => '/admin/content/docusign/ajax-edit-view',
            'variables' => $variables,
          ],
        ],
      ],
    ];

    return $build;
  }

  /**
   * Editor-callback route — handles return from the DocuSign edit-view iframe.
   *
   * Writes field_docusign_template_id on the Library node (if known) and
   * redirects back to the Library edit form with the template ID in the query
   * string so client-side JS can populate the field for fresh nodes.
   *
   * Route: /admin/content/docusign/editor-callback
   */
  public function editorCallback(Request $request) {
    $nid = $request->query->get('nid', '');
    $templateId = $request->query->get('docusign_template_id', '');
    $category = $request->query->get('category', '');

    if ($templateId === '') {
      return new RedirectResponse('/admin/content');
    }

    \Drupal::database()->update('docusign_envelopes')
      ->fields(['status' => 'template_ready', 'updated' => time()])
      ->condition('template_id', $templateId)
      ->execute();

    if ($nid !== '' && $nid !== '0') {
      $libraryNode = Node::load($nid);
      if ($libraryNode && $libraryNode->hasField('field_docusign_template_id')) {
        $libraryNode->set('field_docusign_template_id', $templateId);
        $libraryNode->save();
      }
      $this->messenger()->addStatus($this->t('DocuSign template saved. Template ID: @id', ['@id' => $templateId]));
      return new RedirectResponse('/node/' . $nid . '/edit?docusign_template_id=' . urlencode($templateId));
    }

    $this->messenger()->addStatus($this->t('DocuSign template created. Template ID: @id. Please save the Library item.', ['@id' => $templateId]));
    return new RedirectResponse('/node/add/library?docusign_template_id=' . urlencode($templateId) . '&category=' . urlencode($category));
  }

  /**
   * AJAX: upload a file directly to DocuSign without saving the Library node.
   *
   * Returns { templateId, editViewUrl } for the Library form to redirect into
   * the editor.
   *
   * Route: /admin/content/docusign/ajax-upload (POST, multipart/form-data)
   */
  public function ajaxUploadToDocuSign(Request $request) {
    $file = $request->files->get('file');
    if (!$file) {
      return new JsonResponse(['error' => 'No file provided.'], 400);
    }

    $nid = $request->request->get('nid', '0');
    $category = $request->request->get('category', 'Affidavit');
    $filePath = $file->getRealPath();
    $fileName = $file->getClientOriginalName();
    $templateName = pathinfo($fileName, PATHINFO_FILENAME);
    $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);

    /** @var \Drupal\associatedps_affidavit_template\Service\DocuSignClient $client */
    $client = \Drupal::service('associatedps_affidavit_template.docusign_client');

    $uploadResult = $client->uploadTemplate($filePath, $templateName, $fileExt, $fileName);
    if (isset($uploadResult['error'])) {
      return new JsonResponse(['error' => 'DocuSign upload failed: ' . $uploadResult['error']], 500);
    }

    $templateId = $uploadResult['templateId'];
    \Drupal::logger('docusign')->notice('AJAX template uploaded: @id', ['@id' => $templateId]);

    \Drupal::database()->insert('docusign_envelopes')
      ->fields([
        'template_id' => $templateId,
        'account_id' => $this->resolveAccountId($client),
        'library_nid' => (int) $nid,
        'status' => 'template_uploaded',
        'created' => time(),
        'updated' => time(),
      ])
      ->execute();

    // Bounce URL, not editor-callback — see uploadToDocuSign() comment for
    // the SameSite-cookie rationale.
    $returnUrl = \Drupal::request()->getSchemeAndHttpHost()
      . '/docusign/template-editor-bounce'
      . '?nid=' . $nid
      . '&docusign_template_id=' . urlencode($templateId)
      . '&category=' . urlencode($category);
    $viewResult = $client->createTemplateEditView($templateId, $returnUrl);
    if (isset($viewResult['error'])) {
      return new JsonResponse([
        'error' => 'Template uploaded but editor view failed: ' . $viewResult['error'],
        'templateId' => $templateId,
      ], 500);
    }

    return new JsonResponse([
      'templateId' => $templateId,
      'editViewUrl' => $viewResult['url'],
    ]);
  }

  /**
   * AJAX: refresh an expired edit-view URL for a known template.
   *
   * Used by:
   *   - The "Update at DocuSign" button on a saved Library node.
   *   - Iframe auto-refresh when the single-use URL expires (~5 min).
   *
   * Route: /admin/content/docusign/ajax-edit-view (POST, JSON)
   */
  public function ajaxCreateEditView(Request $request) {
    $data = json_decode((string) $request->getContent(), TRUE) ?: [];
    $templateId = $data['templateId'] ?? '';
    $nid = $data['nid'] ?? '0';
    $category = $data['category'] ?? '';

    if ($templateId === '') {
      return new JsonResponse(['error' => 'No templateId provided.'], 400);
    }

    /** @var \Drupal\associatedps_affidavit_template\Service\DocuSignClient $client */
    $client = \Drupal::service('associatedps_affidavit_template.docusign_client');

    // Bounce URL, not editor-callback — see uploadToDocuSign() comment for
    // the SameSite-cookie rationale.
    $returnUrl = \Drupal::request()->getSchemeAndHttpHost()
      . '/docusign/template-editor-bounce'
      . '?nid=' . $nid
      . '&docusign_template_id=' . urlencode($templateId)
      . '&category=' . urlencode($category);
    $viewResult = $client->createTemplateEditView($templateId, $returnUrl);
    if (isset($viewResult['error'])) {
      return new JsonResponse(['error' => $viewResult['error']], 500);
    }

    return new JsonResponse(['editViewUrl' => $viewResult['url']]);
  }

  /**
   * Generate an Affidavit envelope via DocuSign for a given Job.
   *
   * Route: /docusign/generate-affidavit/{jobId}
   */
  public function generateAffidavit($jobId) {
    return $this->generateViaDocuSign((int) $jobId, 'Affidavit');
  }

  /**
   * Generate a Correspondence envelope via DocuSign for a given Job.
   *
   * Route: /docusign/generate-correspondence/{jobId}
   */
  public function generateCorrespondence($jobId) {
    return $this->generateViaDocuSign((int) $jobId, 'Correspondence');
  }

  /**
   * Core generation logic shared by generateAffidavit/Correspondence.
   *
   * Flow: Find Job's linked Library template (Job → field_documents →
   * field_document → field_affidavit_template → Library with matching
   * category + field_docusign_template_id) → resolve tokens → create
   * draft envelope with prefilled tabs → return envelopeId + status_url
   * for the JS to poll and redirect into the sender-view editor (M7).
   */
  protected function generateViaDocuSign(int $jobId, string $category): JsonResponse {
    $jobNode = Node::load($jobId);
    if (!$jobNode || $jobNode->bundle() !== 'job') {
      return new JsonResponse(['error' => 'Invalid Job node.'], 400);
    }

    $libraryNode = NULL;
    $docusignTemplateId = '';

    // User selected a template explicitly from the modal.
    $request = \Drupal::request();
    $manualLibraryNid = $request->query->get('library_nid');

    if (!empty($manualLibraryNid)) {
      $candidate = Node::load($manualLibraryNid);
      if ($candidate && $candidate->bundle() === 'library'
        && $candidate->hasField('field_docusign_template_id')
        && !empty($candidate->field_docusign_template_id->value)) {
        $libraryNode = $candidate;
        $docusignTemplateId = $candidate->field_docusign_template_id->value;
      }
    }

    // Auto-resolve the template from the job's documents when the user did not
    // pick one manually. Precedence: the PRIMARY document paragraph
    // (field_primary_document ticked) with a valid template wins outright;
    // otherwise the first document (in paragraph order) that carries a valid
    // template is used. "Valid" = a Library of the matching category that has a
    // DocuSign template ID. If no document yields a valid template we fall
    // through to the no_template response and the selection modal is shown.
    if (empty($docusignTemplateId)) {
      $primaryCandidate = NULL;
      $firstCandidate = NULL;

      foreach ($jobNode->get('field_documents') as $docGroup) {
        $paragraph = $docGroup->entity;
        if (!$paragraph) {
          continue;
        }
        $isPrimary = $paragraph->hasField('field_primary_document')
          && (int) $paragraph->get('field_primary_document')->value === 1;

        foreach ($paragraph->get('field_document') as $docItem) {
          $docNode = $docItem->entity;
          if (!$docNode) {
            continue;
          }
          $templateTargetId = $docNode->field_affidavit_template->target_id ?? NULL;
          if (empty($templateTargetId)) {
            continue;
          }
          $candidate = Node::load($templateTargetId);
          if (!$candidate || $candidate->bundle() !== 'library') {
            continue;
          }
          if ($candidate->field_category->value !== $category) {
            continue;
          }
          if (!$candidate->hasField('field_docusign_template_id') || empty($candidate->field_docusign_template_id->value)) {
            continue;
          }

          // Remember the first valid template overall — used only when no
          // primary document contributes one.
          if ($firstCandidate === NULL) {
            $firstCandidate = $candidate;
          }
          // A primary document's valid template takes precedence and wins
          // outright — stop looking once we have it.
          if ($isPrimary) {
            $primaryCandidate = $candidate;
            break 2;
          }
        }
      }

      $chosen = $primaryCandidate ?: $firstCandidate;
      if ($chosen) {
        $libraryNode = $chosen;
        $docusignTemplateId = $chosen->field_docusign_template_id->value;
      }
    }

    if (empty($docusignTemplateId) || !$libraryNode) {
      return new JsonResponse([
        'no_template' => TRUE,
        'category' => $category,
        'job_id' => $jobId,
        'message' => "No {$category} template linked to this job's documents. Please select a template.",
      ], 200);
    }

    /** @var \Drupal\associatedps_affidavit_template\Service\DocuSignClient $client */
    $client = \Drupal::service('associatedps_affidavit_template.docusign_client');
    /** @var \Drupal\associatedps_affidavit_template\Service\DocuSignTokenMapper $mapper */
    $mapper = \Drupal::service('associatedps_affidavit_template.docusign_token_mapper');

    // Resolve tokens from the Job, then shape them into DocuSign tabs.
    $resolved = $mapper->resolveTokensForJob($jobNode);
    $tabs = $mapper->tokensToDocuSignTabs($resolved);

    \Drupal::logger('docusign')->notice('Resolved tabs for Job @jid (@cat): textTabs=@t, dateTabs=@d', [
      '@jid' => $jobId,
      '@cat' => $category,
      '@t' => count($tabs['textTabs'] ?? []),
      '@d' => count($tabs['dateTabs'] ?? []),
    ]);

    $envelopeName = $jobNode->getTitle() . ' - ' . $category . ' - ' . date('Y-m-d H:i');
    $createResult = $client->createEnvelopeFromTemplate(
      $docusignTemplateId,
      $envelopeName,
      $tabs['textTabs'] ?? [],
      $tabs['dateTabs'] ?? []
    );

    if (isset($createResult['error'])) {
      return new JsonResponse(['error' => 'DocuSign envelope creation failed: ' . $createResult['error']], 500);
    }

    $envelopeId = $createResult['envelopeId'];
    $accountId = $this->resolveAccountId($client);

    // Now fill in the actual tab values. DocuSign keys tab updates by tabId,
    // not tabLabel — the client method does the lookup + PUT for both
    // document-level prefill tabs and recipient-bound text/date tabs.
    $valuesByLabel = [];
    foreach ($resolved as $entry) {
      if (!empty($entry['name'])) {
        $valuesByLabel[$entry['name']] = (string) ($entry['value'] ?? '');
      }
    }
    $fillResult = $client->fillEnvelopeTabsByLabel($envelopeId, $valuesByLabel);
    \Drupal::logger('docusign')->notice('Tab fill for envelope @eid: matched=@m, missed=@missed_count, errors=@e', [
      '@eid' => $envelopeId,
      '@m' => $fillResult['matched_count'] ?? 0,
      '@missed_count' => isset($fillResult['missed_labels']) ? count($fillResult['missed_labels']) : 0,
      '@e' => !empty($fillResult['errors']) ? implode('; ', $fillResult['errors']) : '(none)',
    ]);

    // Transition envelope to "sent" so DocuSign renders prefill values into
    // the downloaded PDF. The placeholder signer has clientUserId set
    // (see DocuSignClient::createEnvelopeFromTemplate), so this does NOT
    // generate an email — embedded recipients are never notified.
    $envelopeStatus = $createResult['status'] ?? 'created';
    $sendResult = $client->sendEnvelope($envelopeId, 'sent');
    if (isset($sendResult['error'])) {
      \Drupal::logger('docusign')->warning('sendEnvelope failed for @eid: @err — envelope stays as draft; downloaded PDF will not include prefill values', [
        '@eid' => $envelopeId,
        '@err' => $sendResult['error'],
      ]);
    }
    else {
      $envelopeStatus = $sendResult['status'] ?? 'sent';
    }

    \Drupal::database()->insert('docusign_envelopes')
      ->fields([
        'envelope_id' => $envelopeId,
        'template_id' => $docusignTemplateId,
        'account_id' => $accountId,
        'job_nid' => $jobId,
        'library_nid' => $libraryNode->id(),
        'status' => $envelopeStatus,
        'created' => time(),
        'updated' => time(),
      ])
      ->execute();

    return new JsonResponse([
      'success' => TRUE,
      'envelope_id' => $envelopeId,
      'status_url' => '/docusign/status/' . $envelopeId,
      'editor_url' => '/docusign/envelope-editor/' . $envelopeId . '?job_id=' . $jobId,
      'download_url' => '/docusign/download/' . $envelopeId,
      'message' => "{$category} envelope created. Polling for readiness…",
    ]);
  }

  /**
   * Check DocuSign envelope status.
   *
   * Route: /docusign/status/{envelopeId}
   */
  public function checkStatus($envelopeId) {
    /** @var \Drupal\associatedps_affidavit_template\Service\DocuSignClient $client */
    $client = \Drupal::service('associatedps_affidavit_template.docusign_client');

    $statusResult = $client->getEnvelopeStatus($envelopeId);
    if (isset($statusResult['error'])) {
      return new JsonResponse(['error' => $statusResult['error']], 500);
    }

    $status = $statusResult['status'] ?? 'unknown';

    \Drupal::database()->update('docusign_envelopes')
      ->fields(['status' => $status, 'updated' => time()])
      ->condition('envelope_id', $envelopeId)
      ->execute();

    // Draft envelopes are immediately previewable + downloadable. Any of
    // 'created' / 'sent' / 'delivered' / 'completed' counts as "ready".
    $downloadReady = in_array($status, ['created', 'sent', 'delivered', 'completed'], TRUE);

    return new JsonResponse([
      'envelope_id' => $envelopeId,
      'status' => $status,
      'download_ready' => $downloadReady,
      'download_url' => $downloadReady ? '/docusign/download/' . $envelopeId : NULL,
      'editor_url' => $downloadReady ? '/docusign/envelope-editor/' . $envelopeId : NULL,
    ]);
  }

  /**
   * AJAX: list Library nodes filtered by category that have a DocuSign template.
   * Used by the template-selection modal when a Job has no linked documents.
   *
   * Route: /docusign/templates-by-category/{category}
   */
  public function listTemplatesByCategory($category) {
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'library')
      ->condition('field_docusign_template_id', '', '<>')
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
   * Render the envelope editor page (embedded DocuSign sender view in iframe).
   *
   * Loads after generation so the user can adjust tab placement, recipients,
   * etc. before downloading the PDF.
   *
   * Route: /docusign/envelope-editor/{envelopeId}
   */
  public function envelopeEditorPage($envelopeId, Request $request) {
    $jobId = $request->query->get('job_id', '');

    /** @var \Drupal\associatedps_affidavit_template\Service\DocuSignClient $client */
    $client = \Drupal::service('associatedps_affidavit_template.docusign_client');

    // returnUrl points to a thin signing-callback endpoint that
    // postMessages the parent window when DocuSign redirects the iframe
    // (after Finish/cancel/etc.). The parent then auto-triggers download.
    // Pointing returnUrl back at envelope-editor itself would load the
    // app recursively inside the iframe.
    $returnUrl = \Drupal::request()->getSchemeAndHttpHost()
      . '/docusign/signing-callback/' . $envelopeId
      . ($jobId !== '' ? '?job_id=' . urlencode($jobId) : '');

    $viewResult = $client->createSenderView($envelopeId, $returnUrl);
    $senderViewUrl = $viewResult['url'] ?? '';

    if (isset($viewResult['error']) || $senderViewUrl === '') {
      \Drupal::logger('docusign')->error('Recipient view failed for @id: @err', [
        '@id' => $envelopeId,
        '@err' => $viewResult['error'] ?? 'empty url',
      ]);
      $this->messenger()->addError($this->t('Failed to load DocuSign editor. You can still download the PDF.'));
    }

    $downloadUrl = '/docusign/download/' . $envelopeId;
    $refreshUrl = '/docusign/sender-view/' . $envelopeId;

    $build = [
      'content' => [
        '#type' => 'inline_template',
        '#template' => '<div id="docusign-envelope-editor-wrapper">'
          . '<h2>{{ title }}</h2>'
          . '<p>{{ description }}</p>'
          . '<div id="docusign-envelope-editor-status" class="messages messages--status" style="display:none;"></div>'
          . '<div id="docusign-envelope-editor-actions" style="margin-bottom:10px;">'
          . '<button type="button" id="docusign-download-btn" class="button button--primary" data-envelope-id="{{ envelope_id }}">Download PDF</button> '
          . '<button type="button" id="docusign-back-btn" class="button">Back to Job</button>'
          . '</div>'
          . '<div id="docusign-envelope-editor-container" '
          . 'data-envelope-id="{{ envelope_id }}" '
          . 'data-sender-view-url="{{ sender_view_url }}" '
          . 'data-job-id="{{ job_id }}" '
          . 'data-download-url="{{ download_url }}" '
          . 'data-refresh-url="{{ refresh_url }}"'
          . '></div>'
          . '</div>',
        '#context' => [
          'title' => $this->t('DocuSign Envelope Editor'),
          'description' => $this->t('Review and adjust the generated envelope below. Use Download PDF to grab the final document.'),
          'envelope_id' => $envelopeId,
          'sender_view_url' => $senderViewUrl,
          'job_id' => $jobId,
          'download_url' => $downloadUrl,
          'refresh_url' => $refreshUrl,
        ],
      ],
      '#attached' => [
        'library' => [
          'associatedps_affidavit_template/docusign-envelope-editor',
        ],
        'drupalSettings' => [
          'docusignEnvelopeEditor' => [
            'envelopeId' => $envelopeId,
            'senderViewUrl' => $senderViewUrl,
            'jobId' => $jobId,
            'downloadUrl' => $downloadUrl,
            'refreshUrl' => $refreshUrl,
          ],
        ],
      ],
    ];

    return $build;
  }

  /**
   * AJAX: refresh an expired sender-view URL for an envelope.
   *
   * Used by the envelope editor JS when the single-use sender URL expires
   * (~5 min) so the iframe can reload without losing context.
   *
   * Route: /docusign/sender-view/{envelopeId} (POST)
   */
  public function createSenderViewAjax($envelopeId, Request $request) {
    $jobId = $request->request->get('job_id', $request->query->get('job_id', ''));

    /** @var \Drupal\associatedps_affidavit_template\Service\DocuSignClient $client */
    $client = \Drupal::service('associatedps_affidavit_template.docusign_client');

    $returnUrl = \Drupal::request()->getSchemeAndHttpHost()
      . '/docusign/signing-callback/' . $envelopeId
      . ($jobId !== '' ? '?job_id=' . urlencode($jobId) : '');

    $viewResult = $client->createSenderView($envelopeId, $returnUrl);
    if (isset($viewResult['error'])) {
      return new JsonResponse(['error' => $viewResult['error']], 500);
    }

    return new JsonResponse(['senderViewUrl' => $viewResult['url'] ?? '']);
  }

  /**
   * Thin endpoint that DocuSign's template editor redirects to on Save/Cancel.
   *
   * Same bounce pattern as signingCallback below — see that method for the
   * full SameSite-cookie rationale. The page renders only a postMessage
   * script; the parent admin page (docusign_editor.js) receives it and
   * does a top-level same-site navigation to the protected editor-callback
   * URL, which carries the Drupal session cookie correctly.
   *
   * Route: /docusign/template-editor-bounce (anonymous-accessible)
   */
  public function templateEditorBounce(Request $request) {
    $nid = $request->query->get('nid', '0');
    $templateId = $request->query->get('docusign_template_id', '');
    $category = $request->query->get('category', '');
    $event = $request->query->get('event', '');

    $payload = json_encode([
      'source' => 'aps-docusign',
      'event' => $event !== '' ? $event : 'template_save',
      'nid' => $nid,
      'templateId' => $templateId,
      'category' => $category,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Editor closed</title></head>'
      . '<body style="font-family:sans-serif;padding:20px;background:#f7f7f7;">'
      . '<p>Returning to APS&hellip;</p>'
      . '<script>'
      . '(function(){'
      . 'try { if (window.parent && window.parent !== window) { window.parent.postMessage(' . $payload . ', "*"); } } catch(e){}'
      . '})();'
      . '</script>'
      . '</body></html>';

    $response = new Response($html);
    $response->headers->set('Content-Type', 'text/html; charset=utf-8');
    $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
    return $response;
  }

  /**
   * Thin endpoint that DocuSign's recipient view redirects to on Finish/etc.
   *
   * Returns a minimal HTML page whose only job is to postMessage the parent
   * window (the envelope-editor page) with the signing event so it can
   * react — typically by auto-triggering a fresh PDF download.
   *
   * Why anonymous-accessible (route has `_access: 'TRUE'`): DocuSign's
   * iframe is on docusign.net; when it redirects to our domain, the browser
   * treats it as a cross-origin iframe navigation and SameSite=Lax blocks
   * the Drupal session cookie. The endpoint can't depend on session — so
   * it does the cookie-free postMessage handoff to the parent window
   * (which IS in our same-site top-level context and has the cookie).
   * The parent then runs any protected actions (download, callback, etc.).
   *
   * DocuSign appends an `event` query param: signing_complete, cancel,
   * decline, exception, fax_pending, session_timeout, ttl_expired, viewing_complete.
   *
   * Route: /docusign/signing-callback/{envelopeId}
   */
  public function signingCallback($envelopeId, Request $request) {
    $event = $request->query->get('event', '');
    $jobId = $request->query->get('job_id', '');

    // Reflect the event back to the parent window via postMessage. The
    // parent (envelope-editor.js) decides what to do with it.
    $payload = json_encode([
      'source' => 'aps-docusign',
      'event' => $event,
      'envelopeId' => $envelopeId,
      'jobId' => $jobId,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    // Plain HTML response — NOT a Drupal-themed page, so the iframe
    // renders only the script (no admin chrome).
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Editor closed</title></head>'
      . '<body style="font-family:sans-serif;padding:20px;background:#f7f7f7;">'
      . '<p>Returning to APS&hellip;</p>'
      . '<script>'
      . '(function(){'
      . 'try { if (window.parent && window.parent !== window) { window.parent.postMessage(' . $payload . ', "*"); } } catch(e){}'
      . '})();'
      . '</script>'
      . '</body></html>';

    $response = new Response($html);
    $response->headers->set('Content-Type', 'text/html; charset=utf-8');
    $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
    return $response;
  }

  /**
   * Download the combined PDF for an envelope.
   *
   * Sends `Content-Disposition: inline` so the browser opens it in a new tab
   * (matches the existing Xero invoice / PandaDoc download UX preference).
   *
   * Route: /docusign/download/{envelopeId}
   */
  public function downloadPdf($envelopeId) {
    /** @var \Drupal\associatedps_affidavit_template\Service\DocuSignClient $client */
    $client = \Drupal::service('associatedps_affidavit_template.docusign_client');

    $pdf = $client->downloadCombinedPdf($envelopeId);
    if ($pdf === '') {
      return new JsonResponse(['error' => 'Failed to download PDF from DocuSign.'], 500);
    }

    $response = new Response($pdf);
    $response->headers->set('Content-Type', 'application/pdf');
    $response->headers->set('Content-Disposition', 'inline; filename="envelope_' . $envelopeId . '.pdf"');
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

    return $response;
  }

  /**
   * Helper: read the current account_id from a fresh token row.
   */
  protected function resolveAccountId($client): string {
    $token = $client->getValidAccessToken();
    return $token['account_id'] ?? '';
  }

}
