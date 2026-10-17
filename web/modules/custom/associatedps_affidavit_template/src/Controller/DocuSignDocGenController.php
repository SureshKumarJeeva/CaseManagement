<?php

namespace Drupal\associatedps_affidavit_template\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * DocuSign *Document Generation* integration.
 *
 * The sibling of DocuSignController, not a replacement for it. The two are
 * different products layered on the same REST API and the app now supports
 * both side by side; which one a Library item uses is decided once, at upload
 * time, by the method popup on the Library form:
 *
 *   Template Upload (DocuSignController)
 *     The document is static. The author drags positioned Text/Date *tabs*
 *     onto it and labels them; at generate time we create an envelope and
 *     write values into those tabs. The document's wording never changes.
 *
 *   Document Generation (this controller)
 *     The document is a DOCX carrying *sender fields*, authored either in the
 *     DocuSign console with the Agreement Template Builder or in Word with the
 *     DocuSign Template Assistant add-in. At generate time we push Job data
 *     into those fields and DocuSign RE-RENDERS the DOCX, so conditional
 *     clauses and dynamic tables resolve per job.
 *
 * Runs against its own DocuSign sandbox account throughout — see
 * DocuSignDocGenClient for why the credential sets are kept apart.
 */
class DocuSignDocGenController extends ControllerBase {

  /**
   * Marker written to docusign_envelopes.method for rows owned by this flow.
   */
  public const METHOD = 'docgen';

  /**
   * viewAccess sent with this flow's embedded template edit view.
   *
   * Opts into DocuSign's newer embedded-view security model, which scopes the
   * person in the browser to the one template being edited instead of letting
   * them inherit everything the impersonated API user can reach. Set only on
   * the Document Generation flow — the tab flow stays on DocuSign's backwards
   * compatibility behaviour, which is what it has always run on.
   */
  public const VIEW_ACCESS = 'template';

  /**
   * templateViewSettings sent with this flow's embedded template edit view.
   *
   * Empty on purpose, and the reason is worth keeping: there is no API
   * parameter that opens the Agreement Template Builder. The eSignature API
   * exposes exactly one template view (TemplateViews:createEdit), and probing
   * its startingScreen against the live API (2026-08-17) shows it accepts only
   * two values — 'Prepare' and 'Tagger'. 'Document', 'DocumentGeneration' and
   * 'AgreementTemplateBuilder' are all rejected with INPUT_VALIDATION_ERROR
   * "Invalid startingScreen value". 'Prepare' is already DocuSign's default, so
   * sending it explicitly changes nothing, and every variant encodes the same
   * action in the returned token: prepareTemplateFields.
   *
   * Left as a constant rather than deleted so that if DocuSign adds a docgen
   * screen there is one obvious place to set it.
   */
  public const VIEW_SETTINGS = [];

  /**
   * The DocuSign account used for document generation.
   */
  protected function client(): \Drupal\associatedps_affidavit_template\Service\DocuSignDocGenClient {
    return \Drupal::service('associatedps_affidavit_template.docusign_docgen_client');
  }

  /**
   * Guard: refuse politely when the sandbox account has no credentials.
   *
   * Returns NULL when configured, or the JSON error response to send back.
   */
  protected function configurationGuard(): ?JsonResponse {
    $client = $this->client();
    if ($client->isConfigured()) {
      return NULL;
    }
    return new JsonResponse(['error' => $client->getConfigurationError()], 503);
  }

  // -------------------------------------------------------------------
  // Template creation (Library form → DocuSign → back to Library form).
  // -------------------------------------------------------------------

  /**
   * AJAX: upload a DOCX to the docgen account and open its template editor.
   *
   * Same entry shape as DocuSignController::ajaxUploadToDocuSign so the
   * Library form can call either endpoint from the same file input — only the
   * destination account and the follow-on editing UI differ.
   *
   * DocuSign inspects an uploaded DOCX and marks the template document
   * generation compatible on its own when it finds data fields, so a file
   * already prepared with the Word add-in arrives ready to use. A plain DOCX
   * is equally valid here: the author adds the fields in the next step with
   * the Agreement Template Builder.
   *
   * Route: /admin/content/docusign-docgen/ajax-upload (POST, multipart).
   */
  public function ajaxUpload(Request $request) {
    if ($guard = $this->configurationGuard()) {
      return $guard;
    }

    $file = $request->files->get('file');
    if (!$file) {
      return new JsonResponse(['error' => 'No file provided.'], 400);
    }

    $fileName = $file->getClientOriginalName();
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($fileExt !== 'docx') {
      return new JsonResponse([
        'error' => 'Document Generation needs a .docx file — DocuSign merges data into the Word document itself and re-renders it. '
          . 'Got a .' . $fileExt . ' file. Use Template Upload for PDFs and other formats.',
      ], 400);
    }

    $nid = $request->request->get('nid', '0');
    $category = $request->request->get('category', 'Affidavit');

    $client = $this->client();
    $uploadResult = $client->uploadTemplate(
      $file->getRealPath(),
      pathinfo($fileName, PATHINFO_FILENAME),
      $fileExt,
      $fileName
    );
    if (isset($uploadResult['error'])) {
      return new JsonResponse(['error' => 'DocuSign upload failed: ' . $uploadResult['error']], 500);
    }

    $templateId = $uploadResult['templateId'];
    \Drupal::logger('docusign_docgen')->notice('Doc Gen template uploaded: @id', ['@id' => $templateId]);

    \Drupal::database()->insert('docusign_envelopes')
      ->fields([
        'template_id' => $templateId,
        'account_id' => $this->resolveAccountId(),
        'library_nid' => (int) $nid,
        'method' => self::METHOD,
        'status' => 'template_uploaded',
        'created' => time(),
        'updated' => time(),
      ])
      ->execute();

    $viewResult = $client->createTemplateEditView(
      $templateId,
      $this->bounceUrl($nid, $templateId, $category),
      self::VIEW_ACCESS,
      self::VIEW_SETTINGS
    );
    if (isset($viewResult['error'])) {
      return new JsonResponse([
        'error' => 'Template uploaded but the DocuSign editor could not be opened: ' . $viewResult['error'],
        'templateId' => $templateId,
      ], 500);
    }

    return new JsonResponse([
      'templateId' => $templateId,
      'editViewUrl' => $viewResult['url'],
    ]);
  }

  /**
   * AJAX: mint a fresh edit-view URL for a known docgen template.
   *
   * DocuSign edit-view URLs are single-use and die after ~5 minutes, so both
   * the "Update at DocuSign (Doc Gen)" button and the editor's own idle timer
   * come back through here.
   *
   * Route: /admin/content/docusign-docgen/ajax-edit-view (POST, JSON).
   */
  public function ajaxCreateEditView(Request $request) {
    if ($guard = $this->configurationGuard()) {
      return $guard;
    }

    $data = json_decode((string) $request->getContent(), TRUE) ?: [];
    $templateId = $data['templateId'] ?? '';
    if ($templateId === '') {
      return new JsonResponse(['error' => 'No templateId provided.'], 400);
    }

    $viewResult = $this->client()->createTemplateEditView(
      $templateId,
      $this->bounceUrl($data['nid'] ?? '0', $templateId, $data['category'] ?? ''),
      self::VIEW_ACCESS,
      self::VIEW_SETTINGS
    );
    if (isset($viewResult['error'])) {
      return new JsonResponse(['error' => $viewResult['error']], 500);
    }

    return new JsonResponse(['editViewUrl' => $viewResult['url']]);
  }

  /**
   * The docgen template editor page (embedded DocuSign console in an iframe).
   *
   * The instructions differ from the tab editor's in one way that matters:
   * there is nothing to drag onto the page. The author opens the document with
   * the Agreement Template Builder and adds *sender fields*, and the NAME each
   * field is given is the entire contract between DocuSign and this app — it is
   * what generate-time matching keys on. So the picker below the iframe lists
   * the Job fields by their position on the Job form and hands over the name to
   * use, exactly as the tab editor does for Data Labels.
   *
   * Route: /admin/content/docusign-docgen/editor/{docgenTemplateId}/{nid}
   */
  public function editorPage($docgenTemplateId, $nid, Request $request) {
    $editViewUrl = $request->query->get('edit_view_url', '');
    $category = $request->query->get('category', '');

    /** @var \Drupal\associatedps_affidavit_template\Service\DocuSignTokenMapper $mapper */
    $mapper = \Drupal::service('associatedps_affidavit_template.docusign_token_mapper');
    $variables = $mapper->getJobVariableOptions();

    $callbackUrl = '/admin/content/docusign-docgen/editor-callback'
      . '?nid=' . $nid
      . '&docgen_template_id=' . urlencode($docgenTemplateId)
      . '&category=' . urlencode($category);

    return [
      'content' => [
        '#type' => 'inline_template',
        '#template' => '<div id="docusign-docgen-editor-wrapper">'
          . '<h2>{{ title }}</h2>'
          . '<p>{{ description|raw }}</p>'
          . '<div id="docusign-docgen-editor-status" class="messages messages--status" style="display:none;"></div>'
          . '<div id="docusign-docgen-token-picker-container"></div>'
          . '<div id="docusign-docgen-editor-actions" style="margin-bottom:10px;">'
          . '  <a href="{{ callback_url }}" class="button button--primary">Done Editing — Return to APS</a>'
          . '</div>'
          . '<iframe id="docusign-docgen-editor-iframe" '
          . '        src="{{ edit_view_url }}" '
          . '        data-template-id="{{ template_id }}" '
          . '        data-callback-url="{{ callback_url }}" '
          . '        allow="clipboard-read; clipboard-write" '
          . '        style="width:100%; height:calc(100vh - 160px); min-height:720px; border:1px solid #ccc;">'
          . '</iframe>'
          . '</div>',
        '#context' => [
          'title' => $this->t('DocuSign Document Generation — Template Editor'),
          'description' => $this->t('Open the document with <strong>Edit with Agreement Template Builder</strong> and add <strong>sender fields</strong> where the job data belongs. (A .docx already prepared in Word with the <strong>DocuSign Template Assistant</strong> add-in arrives with its fields intact — just check them here.) <strong>Name each sender field</strong> using the picker below: the name is what this app matches on when it pushes job data in, and a field whose name it does not recognise is simply left for you to fill in by hand at generate time. Punctuation and capitalisation are ignored when matching, so <em>Client.Mail</em>, <em>Client_Mail</em> and <em>ClientMail</em> are all treated as the same field.'),
          'edit_view_url' => $editViewUrl,
          'template_id' => $docgenTemplateId,
          'callback_url' => $callbackUrl,
        ],
      ],
      '#attached' => [
        'library' => ['associatedps_affidavit_template/docusign-docgen-editor'],
        'drupalSettings' => [
          'docusignDocGenEditor' => [
            'templateId' => $docgenTemplateId,
            'editViewUrl' => $editViewUrl,
            'nid' => $nid,
            'callbackUrl' => $callbackUrl,
            'refreshUrl' => '/admin/content/docusign-docgen/ajax-edit-view',
            'variables' => $variables,
          ],
        ],
      ],
    ];
  }

  /**
   * Return from the docgen template editor — capture the template ID.
   *
   * This is requirement "capture the uploaded configured document id into the
   * app": the ID lands in field_docgen_template_id on the Library node, which
   * is what every later generate request resolves against.
   *
   * Route: /admin/content/docusign-docgen/editor-callback
   */
  public function editorCallback(Request $request) {
    $nid = $request->query->get('nid', '');
    $templateId = $request->query->get('docgen_template_id', '');
    $category = $request->query->get('category', '');

    if ($templateId === '') {
      return new RedirectResponse('/admin/content');
    }

    \Drupal::database()->update('docusign_envelopes')
      ->fields(['status' => 'template_ready', 'updated' => time()])
      ->condition('template_id', $templateId)
      ->condition('method', self::METHOD)
      ->execute();

    if ($nid !== '' && $nid !== '0') {
      $libraryNode = Node::load($nid);
      if ($libraryNode && $libraryNode->hasField('field_docgen_template_id')) {
        $libraryNode->set('field_docgen_template_id', $templateId);
        $libraryNode->save();
      }
      $this->messenger()->addStatus($this->t('Doc Gen template saved. Template ID: @id', ['@id' => $templateId]));
      return new RedirectResponse('/node/' . $nid . '/edit?docgen_template_id=' . urlencode($templateId));
    }

    $this->messenger()->addStatus($this->t('Doc Gen template created. Template ID: @id. Please save the Library item.', ['@id' => $templateId]));
    return new RedirectResponse('/node/add/library?docgen_template_id=' . urlencode($templateId) . '&category=' . urlencode($category));
  }

  /**
   * Bounce target for the docgen template editor iframe.
   *
   * Anonymous-accessible for the same SameSite-cookie reason documented at
   * length on DocuSignController::signingCallback(): DocuSign's iframe lives on
   * docusign.net, so its redirect back to us is a cross-origin navigation and
   * the browser withholds the Drupal session cookie. This page therefore does
   * nothing that needs a session — it postMessages the parent window, which is
   * same-site, holds the cookie, and runs the protected callback itself.
   *
   * Route: /docusign-docgen/template-editor-bounce (anonymous).
   */
  public function templateEditorBounce(Request $request) {
    return $this->bounceResponse([
      'source' => 'aps-docusign-docgen',
      'event' => $request->query->get('event', '') ?: 'template_save',
      'nid' => $request->query->get('nid', '0'),
      'templateId' => $request->query->get('docgen_template_id', ''),
      'category' => $request->query->get('category', ''),
    ]);
  }

  // -------------------------------------------------------------------
  // Generation (Job form → envelope → DocuSign sender view → PDF).
  // -------------------------------------------------------------------

  /**
   * Generate a document-generation envelope for a Job.
   *
   * Called by DocuSignController::generateViaDocuSign() once it has resolved
   * the Job's template and found it to be a docgen one, so the user still uses
   * the single "Generate Affidavit" / "Generate Correspondence" button and
   * never has to know which mechanism a given template uses.
   *
   * The sequence is forced by the API: DocuSign only reveals a document's
   * generation fields once an envelope exists, and it addresses them by a
   * document GUID that is unknowable up front. So: create the draft, ask what
   * fields it has, match Job data onto them, push the values back (which is
   * what makes DocuSign re-render the DOCX), and hand the envelope to the user
   * in DocuSign's own sender view for final edits.
   *
   * The envelope stays at status "created" throughout. That is deliberate and
   * load-bearing twice over: nothing is emailed to anyone from a draft, and the
   * embedded sender view — the only view that shows the document generation
   * form — is valid on drafts only.
   */
  public function generateForJob(NodeInterface $jobNode, NodeInterface $libraryNode, string $templateId, string $category): JsonResponse {
    if ($guard = $this->configurationGuard()) {
      return $guard;
    }

    $client = $this->client();
    /** @var \Drupal\associatedps_affidavit_template\Service\DocuSignTokenMapper $mapper */
    $mapper = \Drupal::service('associatedps_affidavit_template.docusign_token_mapper');

    $envelopeName = $jobNode->getTitle() . ' - ' . $category . ' - ' . date('Y-m-d H:i');
    $createResult = $client->createDraftEnvelopeFromTemplate($templateId, $envelopeName);
    if (isset($createResult['error'])) {
      return new JsonResponse(['error' => 'DocuSign envelope creation failed: ' . $createResult['error']], 500);
    }
    $envelopeId = $createResult['envelopeId'];

    $formFields = $client->getDocGenFormFields($envelopeId);
    if (isset($formFields['error'])) {
      return new JsonResponse([
        'error' => 'Envelope created but its document generation fields could not be read: ' . $formFields['error'],
      ], 500);
    }

    $declared = $formFields['docGenFormFields'] ?? [];
    if (empty($declared)) {
      // Not an API failure — the template simply has no sender fields, which
      // means whoever built it never added any. Say so precisely, because the
      // fix is in the Agreement Template Builder, not here.
      \Drupal::logger('docusign_docgen')->warning('Envelope @eid from template @tid declares no docgen fields.', [
        '@eid' => $envelopeId,
        '@tid' => $templateId,
      ]);
      return new JsonResponse([
        'error' => 'This template has no document generation fields. Open the Library item and add sender fields to the document with the Agreement Template Builder.',
      ], 422);
    }

    $mapped = $mapper->resolvedToDocGenFields($declared, $mapper->resolveTokensForJob($jobNode));

    if (!empty($mapped['payload'])) {
      // update_docgen_formfields_only stays FALSE — that is what makes
      // DocuSign merge the values and regenerate the document, rather than
      // just recording them.
      $updateResult = $client->updateDocGenFormFields($envelopeId, $mapped['payload'], FALSE);
      if (isset($updateResult['error'])) {
        // Not fatal: the envelope exists and the sender view still opens, so
        // the user can type the values in themselves rather than lose the run.
        \Drupal::logger('docusign_docgen')->error('Prefill failed for envelope @eid: @err', [
          '@eid' => $envelopeId,
          '@err' => $updateResult['error'],
        ]);
      }
    }

    \Drupal::logger('docusign_docgen')->notice('Doc Gen envelope @eid for Job @jid (@cat): prefilled @m field(s), left @u for the sender.', [
      '@eid' => $envelopeId,
      '@jid' => $jobNode->id(),
      '@cat' => $category,
      '@m' => $mapped['matched'],
      '@u' => count($mapped['unmatched']),
    ]);

    \Drupal::database()->insert('docusign_envelopes')
      ->fields([
        'envelope_id' => $envelopeId,
        'template_id' => $templateId,
        'account_id' => $this->resolveAccountId(),
        'job_nid' => (int) $jobNode->id(),
        'library_nid' => (int) $libraryNode->id(),
        'method' => self::METHOD,
        'status' => $createResult['status'] ?? 'created',
        'created' => time(),
        'updated' => time(),
      ])
      ->execute();

    return new JsonResponse([
      'success' => TRUE,
      'method' => self::METHOD,
      'envelope_id' => $envelopeId,
      'prefilled' => $mapped['matched'],
      'unmatched_fields' => array_values(array_unique($mapped['unmatched'])),
      'status_url' => '/docusign-docgen/status/' . $envelopeId,
      'editor_url' => '/docusign-docgen/envelope-editor/' . $envelopeId . '?job_id=' . $jobNode->id(),
      'download_url' => '/docusign-docgen/download/' . $envelopeId,
      'message' => "{$category} document generated. Opening DocuSign for review…",
    ]);
  }

  /**
   * Envelope status, polled by the generate button while it waits.
   *
   * Route: /docusign-docgen/status/{envelopeId}
   */
  public function checkStatus($envelopeId) {
    if ($guard = $this->configurationGuard()) {
      return $guard;
    }

    $statusResult = $this->client()->getEnvelopeStatus($envelopeId);
    if (isset($statusResult['error'])) {
      return new JsonResponse(['error' => $statusResult['error']], 500);
    }

    $status = $statusResult['status'] ?? 'unknown';

    \Drupal::database()->update('docusign_envelopes')
      ->fields(['status' => $status, 'updated' => time()])
      ->condition('envelope_id', $envelopeId)
      ->execute();

    $ready = in_array($status, ['created', 'sent', 'delivered', 'completed'], TRUE);

    return new JsonResponse([
      'envelope_id' => $envelopeId,
      'status' => $status,
      'download_ready' => $ready,
      'download_url' => $ready ? '/docusign-docgen/download/' . $envelopeId : NULL,
      'editor_url' => $ready ? '/docusign-docgen/envelope-editor/' . $envelopeId : NULL,
    ]);
  }

  /**
   * The generated-document review page (embedded DocuSign sender view).
   *
   * This is where the user "edits the field values alone": DocuSign shows its
   * document generation form with our pushed values already in it, regenerates
   * the document from any change, and previews the result. The document body
   * itself stays the template's — only field values are editable.
   *
   * Route: /docusign-docgen/envelope-editor/{envelopeId}
   */
  public function envelopeEditorPage($envelopeId, Request $request) {
    $jobId = $request->query->get('job_id', '');

    $viewResult = $this->client()->createDraftSenderView($envelopeId, $this->senderReturnUrl($envelopeId, $jobId));
    $senderViewUrl = $viewResult['url'] ?? '';

    if (isset($viewResult['error']) || $senderViewUrl === '') {
      \Drupal::logger('docusign_docgen')->error('Sender view failed for @id: @err', [
        '@id' => $envelopeId,
        '@err' => $viewResult['error'] ?? 'empty url',
      ]);
      $this->messenger()->addError($this->t('Failed to load the DocuSign editor. You can still download the PDF.'));
    }

    $downloadUrl = '/docusign-docgen/download/' . $envelopeId;
    $refreshUrl = '/docusign-docgen/sender-view/' . $envelopeId;

    return [
      'content' => [
        '#type' => 'inline_template',
        '#template' => '<div id="docusign-docgen-envelope-editor-wrapper">'
          . '<h2>{{ title }}</h2>'
          . '<p>{{ description }}</p>'
          . '<div id="docusign-docgen-envelope-editor-status" class="messages messages--status" style="display:none;"></div>'
          . '<div id="docusign-docgen-envelope-editor-actions" style="margin-bottom:10px;">'
          . '<button type="button" id="docusign-docgen-download-btn" class="button button--primary" data-envelope-id="{{ envelope_id }}">Download PDF</button> '
          . '<button type="button" id="docusign-docgen-back-btn" class="button">Back to Job</button>'
          . '</div>'
          . '<div id="docusign-docgen-envelope-editor-container" '
          . 'data-envelope-id="{{ envelope_id }}" '
          . 'data-sender-view-url="{{ sender_view_url }}" '
          . 'data-job-id="{{ job_id }}" '
          . 'data-download-url="{{ download_url }}" '
          . 'data-refresh-url="{{ refresh_url }}"'
          . '></div>'
          . '</div>',
        '#context' => [
          'title' => $this->t('DocuSign Document Generation — Review'),
          'description' => $this->t('The document has been generated from this job\'s data. Adjust any field values below and DocuSign will regenerate the document. When you are finished, close the DocuSign panel and use Download PDF.'),
          'envelope_id' => $envelopeId,
          'sender_view_url' => $senderViewUrl,
          'job_id' => $jobId,
          'download_url' => $downloadUrl,
          'refresh_url' => $refreshUrl,
        ],
      ],
      '#attached' => [
        'library' => ['associatedps_affidavit_template/docusign-docgen-envelope-editor'],
        'drupalSettings' => [
          'docusignDocGenEnvelopeEditor' => [
            'envelopeId' => $envelopeId,
            'senderViewUrl' => $senderViewUrl,
            'jobId' => $jobId,
            'downloadUrl' => $downloadUrl,
            'refreshUrl' => $refreshUrl,
          ],
        ],
      ],
    ];
  }

  /**
   * AJAX: mint a fresh sender-view URL (they are single-use, ~5 min).
   *
   * Route: /docusign-docgen/sender-view/{envelopeId} (POST)
   */
  public function createSenderViewAjax($envelopeId, Request $request) {
    if ($guard = $this->configurationGuard()) {
      return $guard;
    }

    $jobId = $request->request->get('job_id', $request->query->get('job_id', ''));
    $viewResult = $this->client()->createDraftSenderView($envelopeId, $this->senderReturnUrl($envelopeId, $jobId));
    if (isset($viewResult['error'])) {
      return new JsonResponse(['error' => $viewResult['error']], 500);
    }

    return new JsonResponse(['senderViewUrl' => $viewResult['url'] ?? '']);
  }

  /**
   * Bounce target for the sender-view iframe. Anonymous — see
   * templateEditorBounce() for the cookie rationale.
   *
   * DocuSign appends its own ?event= here: send, save, cancel, sessionEnd…
   *
   * Route: /docusign-docgen/sender-callback/{envelopeId} (anonymous).
   */
  public function senderCallback($envelopeId, Request $request) {
    return $this->bounceResponse([
      'source' => 'aps-docusign-docgen',
      'event' => $request->query->get('event', ''),
      'envelopeId' => $envelopeId,
      'jobId' => $request->query->get('job_id', ''),
    ]);
  }

  /**
   * Download the generated document as a PDF.
   *
   * Inline disposition so it opens in a new tab, matching the Xero invoice and
   * tab-flow download behaviour.
   *
   * Route: /docusign-docgen/download/{envelopeId}
   */
  public function downloadPdf($envelopeId) {
    if ($guard = $this->configurationGuard()) {
      return $guard;
    }

    $pdf = $this->client()->downloadCombinedPdf($envelopeId);
    if ($pdf === '') {
      return new JsonResponse(['error' => 'Failed to download the generated PDF from DocuSign.'], 500);
    }

    $response = new Response($pdf);
    $response->headers->set('Content-Type', 'application/pdf');
    $response->headers->set('Content-Disposition', 'inline; filename="docgen_' . $envelopeId . '.pdf"');
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
    return $response;
  }

  // -------------------------------------------------------------------
  // Shared helpers.
  // -------------------------------------------------------------------

  /**
   * Return URL for the template editor iframe.
   */
  protected function bounceUrl($nid, string $templateId, string $category): string {
    return \Drupal::request()->getSchemeAndHttpHost()
      . '/docusign-docgen/template-editor-bounce'
      . '?nid=' . urlencode((string) $nid)
      . '&docgen_template_id=' . urlencode($templateId)
      . '&category=' . urlencode($category);
  }

  /**
   * Return URL for the sender-view iframe.
   */
  protected function senderReturnUrl(string $envelopeId, $jobId): string {
    return \Drupal::request()->getSchemeAndHttpHost()
      . '/docusign-docgen/sender-callback/' . $envelopeId
      . (($jobId !== '' && $jobId !== NULL) ? '?job_id=' . urlencode((string) $jobId) : '');
  }

  /**
   * A minimal HTML page whose only job is to postMessage the parent window.
   */
  protected function bounceResponse(array $payload): Response {
    $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Returning to APS</title></head>'
      . '<body style="font-family:sans-serif;padding:20px;background:#f7f7f7;">'
      . '<p>Returning to APS&hellip;</p>'
      . '<script>'
      . '(function(){'
      . 'try { if (window.parent && window.parent !== window) { window.parent.postMessage(' . $json . ', "*"); } } catch(e){}'
      . '})();'
      . '</script>'
      . '</body></html>';

    $response = new Response($html);
    $response->headers->set('Content-Type', 'text/html; charset=utf-8');
    $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
    return $response;
  }

  /**
   * The docgen account's GUID, for the tracking table.
   */
  protected function resolveAccountId(): string {
    $token = $this->client()->getValidAccessToken();
    return $token['account_id'] ?? '';
  }

}
