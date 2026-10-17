<?php

namespace Drupal\associatedps_affidavit_template\Controller;

use Drupal\associatedps_affidavit_template\Service\GraphDriveClient;
use Drupal\associatedps_affidavit_template\Service\M365EditSessionStore;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use Drupal\file\FileRepositoryInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Affidavit template authoring through Microsoft 365 / Word for the web.
 *
 * The round trip:
 *   ajaxUpload()  the author picks a .docx on the Library form. It is pushed
 *                 straight into our SharePoint library, the (driveId, itemId)
 *                 pair is recorded against the node, and the edit URL comes
 *                 back so the browser can drop it into an already-open tab.
 *   pull()        the Done button. Downloads the current bytes from SharePoint,
 *                 writes them back onto the node's field_import_template as a
 *                 new file + node revision, then removes the SharePoint copy.
 *   discard()     the author changed their mind; drop the remote copy.
 *
 * WHY THE EDITED FILE GOES BACK ONTO field_import_template:
 * that is the field every downstream consumer already reads (see
 * DocuSignController::uploadToDocuSign()), and it is what the Python docxtpl
 * merge will read too. A file left in a loose folder is invisible to all of it.
 *
 * WHY A NEW TAB AND NOT AN IFRAME:
 * Microsoft restricts framing of an editable Office for the web session to its
 * own origins. The only embeddable actions SharePoint offers (embedview,
 * interactivepreview) are read-only; editable framing requires WOPI, which is
 * Cloud Storage Partner Program only. The Drupal tab stays open beside the
 * editor and carries the Done button, which gets the same result without
 * relying on something Microsoft blocks.
 *
 * WHY THE TAB IS OPENED BY JS AND NOT BY A SERVER REDIRECT:
 * a server response cannot open a tab, and window.open() outside a user-gesture
 * call stack is blocked by every popup blocker. The file input's change event IS
 * a gesture, so the JS opens a blank tab synchronously there and only sets its
 * location once this controller answers.
 */
class M365TemplateController extends ControllerBase {

  protected GraphDriveClient $graph;
  protected M365EditSessionStore $sessions;
  protected FileSystemInterface $fileSystem;
  protected FileRepositoryInterface $fileRepository;

  public function __construct(
    GraphDriveClient $graph,
    M365EditSessionStore $sessions,
    FileSystemInterface $file_system,
    FileRepositoryInterface $file_repository
  ) {
    $this->graph = $graph;
    $this->sessions = $sessions;
    $this->fileSystem = $file_system;
    $this->fileRepository = $file_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('associatedps_affidavit_template.graph_drive'),
      $container->get('associatedps_affidavit_template.m365_sessions'),
      $container->get('file_system'),
      $container->get('file.repository')
    );
  }

  /**
   * Push the chosen .docx to SharePoint and hand back its edit URL.
   *
   * Called by the Library form's file-input handler, mirroring the existing
   * DocuSign ajax-upload endpoint.
   *
   * Expects a multipart POST carrying 'file' and 'nid'.
   */
  public function ajaxUpload(Request $request): JsonResponse {
    $nid = (int) $request->request->get('nid');
    $uploaded = $request->files->get('file');

    // Validate the target BEFORE anything is sent to Microsoft. Uploading first
    // and discovering afterwards that there is nowhere to put the result leaves
    // an orphan in SharePoint and, worse, lets someone edit a document that can
    // never come back. The Library add form now captures the title and saves in
    // a first step precisely so a node id always exists by this point.
    $node = $nid > 0 ? Node::load($nid) : NULL;
    if (!$node || $node->bundle() !== 'library') {
      return new JsonResponse(['error' => 'This template could not be identified. Reload the page and try again.'], 400);
    }
    if (!$node->access('update')) {
      return new JsonResponse(['error' => 'You do not have permission to update this template.'], 403);
    }

    if (!$uploaded) {
      return new JsonResponse(['error' => 'No file was received.'], 400);
    }
    $original_name = (string) $uploaded->getClientOriginalName();
    if (!preg_match('/\.docx$/i', $original_name)) {
      return new JsonResponse(['error' => 'Word for the web editing needs a .docx file.'], 400);
    }

    $bytes = @file_get_contents($uploaded->getRealPath());
    if ($bytes === FALSE || $bytes === '') {
      return new JsonResponse(['error' => 'The uploaded file was empty.'], 400);
    }

    return $this->startSession($node, $original_name, $bytes);
  }

  /**
   * Push bytes to SharePoint, record the session, and describe it to the browser.
   *
   * Shared by the first upload (ajaxUpload) and every later edit (reopen), so
   * both produce an identical panel and there is only one place where the ids
   * are captured.
   */
  protected function startSession(Node $node, string $file_name, string $bytes): JsonResponse {
    $nid = (int) $node->id();

    try {
      $item = $this->graph->uploadDocx($file_name, $bytes);
    }
    catch (\Throwable $e) {
      // GraphDriveClient's message already explains a missing
      // Sites.ReadWrite.All grant in plain terms, so pass it straight through.
      return new JsonResponse(['error' => $e->getMessage()], 502);
    }

    $item_id = (string) ($item['id'] ?? '');
    // The upload response carries the drive the file landed in, so no second
    // call is needed. The fallback only matters if Microsoft ever omits
    // parentReference.
    $drive_id = (string) ($item['parentReference']['driveId'] ?? '');
    if ($drive_id === '') {
      try {
        $drive_id = $this->graph->getDriveId();
      }
      catch (\Throwable $e) {
        $drive_id = '';
      }
    }

    if ($item_id === '' || $drive_id === '') {
      return new JsonResponse(['error' => 'Microsoft 365 accepted the upload but did not identify the stored file.'], 502);
    }

    // An expiring anonymous sharing link, falling back to the plain item URL.
    //
    // WHY NOT JUST THE ITEM URL: our app-only token authorises the Graph calls
    // above, but Word for the web is a page rendering in the author's browser
    // and SharePoint authenticates that by cookie. With the plain URL only
    // someone holding tenant credentials could author a template. The sharing
    // link carries its own authorisation, scoped to this one file — verified in
    // a clean browser: the editor opened without a sign-in and the site root
    // stayed out of reach. It expires in an hour and the file is deleted at
    // Done, so the link dies with the session either way.
    $authoring = $this->graph->getAuthoringUrl($item, $drive_id, $item_id);
    $edit_url = $authoring['url'];
    if ($edit_url === '') {
      return new JsonResponse(['error' => 'Microsoft 365 returned no edit URL for the document.'], 502);
    }

    $session_id = $this->sessions->open(
      $nid,
      $drive_id,
      $item_id,
      (string) ($item['name'] ?? $file_name),
      (string) ($item['cTag'] ?? ''),
      (int) $this->currentUser()->id()
    );

    return new JsonResponse([
      'sessionId' => $session_id,
      'itemId' => $item_id,
      'driveId' => $drive_id,
      'fileName' => $item['name'] ?? $file_name,
      'editUrl' => $edit_url,
      // FALSE means the tenant refused the sharing link and the author will be
      // asked to sign in to Microsoft — worth saying in the panel rather than
      // leaving them at an unexplained login screen.
      'anonymousLink' => $authoring['anonymous'],
      'pullUrl' => Url::fromRoute('associatedps_affidavit_template.m365_pull', ['nid' => $nid])->toString(),
      'discardUrl' => Url::fromRoute('associatedps_affidavit_template.m365_discard', ['nid' => $nid])->toString(),
    ]);
  }

  /**
   * The Done button: bring the edited document back onto the node.
   *
   * ⚠ Word for the web autosaves into its own co-authoring session, NOT
   * straight to the drive item — see waitForCommit() for why that distinction
   * cost us a silently overwritten template.
   *
   * The request may carry a `category` field. The Library ADD flow creates the
   * node with only a Title, so at this point the category the author picked
   * exists only in the unsubmitted form; without it the page reloads to an
   * empty category, the #states rule hides the file element, and the freshly
   * authored document looks lost. See applyPendingCategory().
   */
  public function pull(int $nid, Request $request): JsonResponse {
    $node = Node::load($nid);
    if (!$node || $node->bundle() !== 'library') {
      return new JsonResponse(['error' => 'Invalid Library node.'], 404);
    }
    if (!$node->access('update')) {
      return new JsonResponse(['error' => 'You do not have permission to update this template.'], 403);
    }

    $session = $this->sessions->getOpen($nid);
    if (!$session) {
      return new JsonResponse(['error' => 'There is no open Microsoft 365 editing session for this template.'], 404);
    }

    try {
      // ⚠⚠ WAIT FOR WORD TO COMMIT BEFORE READING. Word for the web keeps
      // edits in its own co-authoring session and writes them to the drive
      // item periodically and on session close — NOT on every keystroke. Pull
      // immediately and Graph serves the version from before the author typed
      // anything: the download returns the old bytes and the cTag still
      // matches, so the author is told "no changes" while their work sits in
      // SharePoint, saved, a few seconds later.
      //
      // Observed exactly that on 2026-08-28: a token typed on page 1, Done
      // pressed 22 seconds later, cTag unchanged at "c:{...},2", and the file
      // written back to the node had no token in it.
      //
      // So poll until the cTag moves rather than trusting the first read. The
      // client now closes the Word tab BEFORE calling this, which is what
      // triggers the final save, so this usually settles in a few seconds.
      $item = $this->waitForCommit($session);
      $bytes = $this->graph->downloadItem($session['drive_id'], $session['item_id']);
    }
    catch (\Throwable $e) {
      return new JsonResponse(['error' => $e->getMessage()], 502);
    }

    if ($bytes === '') {
      return new JsonResponse(['error' => 'Microsoft 365 returned an empty file; the template has not been changed.'], 502);
    }

    // cTag changes only when the CONTENT changes, so this distinguishes "the
    // author edited nothing" from "the author edited something". Both are
    // saved — the point is to say which happened, because silently reporting
    // success on an untouched document is how people lose work they believed
    // they had saved.
    //
    // After waitForCommit() this reads "still unchanged after we waited",
    // which is a far stronger claim than the single read it replaced.
    $unchanged = $session['c_tag'] !== '' && $session['c_tag'] === (string) ($item['cTag'] ?? '');

    $file_name = (string) ($item['name'] ?? ($session['file_name'] ?: 'template.docx'));
    $destination = $this->destinationDirectory($node) . '/' . $file_name;

    try {
      // A new File entity rather than overwriting the old one in place: the
      // node takes a new revision pointing at the new file, so the previous
      // version of a court template stays retrievable through node revisions.
      $file = $this->fileRepository->writeData($bytes, $destination, FileExists::Rename);
    }
    catch (\Throwable $e) {
      return new JsonResponse(['error' => 'Could not write the document to the server: ' . $e->getMessage()], 500);
    }

    $node->set('field_import_template', ['target_id' => $file->id()]);
    $this->applyPendingCategory($node, (string) $request->request->get('category', ''));
    if ($node->getEntityType()->isRevisionable()) {
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage('Template updated from Microsoft 365 (Word for the web).');
      $node->setRevisionUserId((int) $this->currentUser()->id());
    }
    $node->save();

    // Only once the bytes are safely on disk AND attached to the node is it
    // safe to remove the SharePoint copy.
    //
    // This delete usually FAILS with 423 resourceLocked, and that is expected
    // rather than broken: Word for the web holds a lock for several minutes
    // after the last editor closes, and the author presses Done while the
    // editor tab is often still open. The document is already saved by this
    // point, so the session is parked for cron to retry instead of failing an
    // operation that actually succeeded.
    if ($this->removeRemote($session, 'saved node ' . $nid . ' from SharePoint')) {
      $this->sessions->markPulled((int) $session['id']);
    }
    else {
      $this->sessions->markCleanupPending((int) $session['id']);
    }

    return new JsonResponse([
      'saved' => TRUE,
      'unchanged' => $unchanged,
      'fileName' => $file->getFilename(),
      'message' => $unchanged
        ? 'Saved — but the document came back unchanged, so no edits were detected.'
        : 'The edited template has been saved back to this Library item.',
    ]);
  }

  /**
   * Abandon an open session without pulling anything back.
   *
   * Court documents should not be left sitting in SharePoint because someone
   * changed their mind, so this removes the remote copy as well as closing the
   * session.
   */
  public function discard(int $nid): JsonResponse {
    $node = Node::load($nid);
    if (!$node || $node->bundle() !== 'library' || !$node->access('update')) {
      return new JsonResponse(['error' => 'Invalid Library node.'], 404);
    }

    $session = $this->sessions->getOpen($nid);
    if ($session) {
      if ($this->removeRemote($session, 'discarded')) {
        $this->sessions->markAbandoned((int) $session['id']);
      }
      else {
        // Same lock story as pull(); cron finishes the job.
        $this->sessions->markCleanupPending((int) $session['id']);
      }
    }

    return new JsonResponse(['discarded' => TRUE]);
  }

  /**
   * Re-open an existing template for another round of editing.
   *
   * The "Update at DocuSign" equivalent, but simpler: DocuSign has to remember
   * a template id because the authoritative copy lives at DocuSign. Here the
   * authoritative copy is ours — field_import_template on this node — and
   * SharePoint is only ever a temporary workspace. So there is nothing to
   * remember: this just pushes the current server file up again.
   */
  public function reopen(int $nid): JsonResponse {
    $node = Node::load($nid);
    if (!$node || $node->bundle() !== 'library') {
      return new JsonResponse(['error' => 'Invalid Library node.'], 404);
    }
    if (!$node->access('update')) {
      return new JsonResponse(['error' => 'You do not have permission to update this template.'], 403);
    }

    $value = $node->get('field_import_template')->getValue();
    if (empty($value[0]['target_id'])) {
      return new JsonResponse(['error' => 'This Library item has no document to edit.'], 400);
    }

    $file = $this->entityTypeManager()->getStorage('file')->load($value[0]['target_id']);
    if (!$file) {
      return new JsonResponse(['error' => 'The attached document could not be found.'], 404);
    }

    $name = $file->getFilename();
    if (!preg_match('/\.docx$/i', $name)) {
      return new JsonResponse(['error' => 'Only .docx documents can be edited in Word for the web.'], 400);
    }

    $bytes = @file_get_contents($file->getFileUri());
    if ($bytes === FALSE || $bytes === '') {
      return new JsonResponse(['error' => 'The attached document could not be read from the server.'], 500);
    }

    return $this->startSession($node, $name, $bytes);
  }

  /**
   * Delete the SharePoint copy, reporting whether it actually went.
   *
   * @return bool
   *   TRUE if the remote copy is gone, FALSE if it must be retried later.
   */
  protected function removeRemote(array $session, string $context): bool {
    try {
      $this->graph->deleteItem($session['drive_id'], $session['item_id']);
      return TRUE;
    }
    catch (\Throwable $e) {
      // Logged as info, not warning: a locked file here is the normal case and
      // cron resolves it. Genuine failures surface when cron keeps retrying.
      $this->getLogger('associatedps_affidavit_template')->info(
        'M365: @context but the remote copy (@item) is still locked; queued for cleanup. @msg',
        ['@context' => $context, '@item' => $session['item_id'], '@msg' => $e->getMessage()]
      );
      return FALSE;
    }
  }

  /**
   * Connectivity and permission report.
   *
   * This exists because nothing here works until an administrator grants
   * Sites.ReadWrite.All in Entra, and the failure without it is a bare 403 that
   * reads like a bug. This page says exactly what is missing.
   */
  public function status(): array {
    $d = $this->graph->diagnostics();

    $rows = [
      [$this->t('Credentials in .env'), $d['configured'] ? $this->t('Present') : $this->t('MISSING')],
      [$this->t('Access token'), $d['token'] ? $this->t('Acquired') : $this->t('Failed')],
      [$this->t('Granted application permissions'), $d['roles'] ? implode(', ', $d['roles']) : $this->t('none')],
      [
        $this->t('Can write to site libraries'),
        $d['has_sites_write'] ? $this->t('Yes') : $this->t('NO — Sites.ReadWrite.All is not granted'),
      ],
      [
        $this->t('Target site (MS_GRAPH_SITE_ID)'),
        $d['site']['displayName'] ?? $d['site']['name'] ?? $d['site_id'],
      ],
      [$this->t('Document library'), $d['drive']['name'] ?? $this->t('not reachable')],
      [$this->t('Library id (driveId)'), $d['drive']['id'] ?? $this->t('unknown')],
      [$this->t('Folder used for templates'), $d['folder']],
    ];

    $build = [];

    if ($d['errors']) {
      $build['errors'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Problems'),
        '#items' => $d['errors'],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Check'), $this->t('Result')],
      '#rows' => $rows,
    ];

    if (!$d['has_sites_write']) {
      $build['howto'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('To enable this, a Microsoft 365 administrator must:'),
        '#items' => [
          $this->t('Open Entra ID → App registrations → this application → API permissions.'),
          $this->t('Add a Microsoft Graph Application permission: Sites.ReadWrite.All. (Sites, not Files — we target a site document library, not personal OneDrive.)'),
          $this->t('Click "Grant admin consent".'),
          $this->t('Then use the button below. A token minted before the grant keeps its old permissions for up to an hour, which looks exactly like the consent not having worked.'),
        ],
      ];
    }

    $build['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Clear the cached access token'),
      '#url' => Url::fromRoute('associatedps_affidavit_template.m365_reset_token'),
      '#attributes' => ['class' => ['button']],
    ];

    return $build;
  }

  /**
   * Drop the cached app-only token and return to the status page.
   */
  public function resetToken(): RedirectResponse {
    $this->graph->resetToken();
    $this->messenger()->addStatus($this->t('Cached Microsoft Graph token cleared. The next request will mint a fresh one carrying current permissions.'));
    return $this->redirect('associatedps_affidavit_template.m365_status');
  }

  /**
   * Persist the category the author chose but never got to submit.
   *
   * WHY THIS IS NEEDED: the Library ADD flow creates the node from step one
   * with only a Title. The author then picks a category — which is what makes
   * the file element appear at all, via a #states rule — and uploads a .docx,
   * which opens Word. Pressing Done saves the FILE onto the node, but the
   * category was only ever in the browser's unsubmitted form. The page then
   * reloads, the category reads back empty, #states hides the file element,
   * and the document the author just wrote appears to have vanished. It has
   * not — but they must re-pick the category and press Save to see it again.
   *
   * Saving it here, alongside the file, leaves the node in a state that
   * matches what the author actually did.
   *
   * ⚠ The reload itself must NOT be removed to fix this. It exists because the
   * open form still references the OLD file, so pressing Save on it would
   * write that file back over the one just authored.
   *
   * ⚠ Only fills an EMPTY category. An existing template keeps the category it
   * was saved with: a value typed into a form and never submitted must not
   * silently overwrite stored data, and the reported problem is only ever the
   * empty case.
   */
  protected function applyPendingCategory(Node $node, string $category): void {
    if ($category === '' || !$node->hasField('field_category')) {
      return;
    }
    if (!$node->get('field_category')->isEmpty()) {
      return;
    }

    // The value now arrives in a request body, so never write one the field
    // does not define.
    $allowed = $node->getFieldDefinition('field_category')
      ->getFieldStorageDefinition()
      ->getSetting('allowed_values') ?: [];
    if (!array_key_exists($category, $allowed)) {
      $this->getLogger('m365')->warning('Ignored unknown category "@cat" on template @nid.', [
        '@cat' => $category,
        '@nid' => $node->id(),
      ]);
      return;
    }

    $node->set('field_category', $category);
  }

  /**
   * How long to wait for Word for the web to commit the author's edits.
   *
   * Word does not write to the drive item on every keystroke; it flushes
   * periodically and when the editing session ends. A few seconds covers the
   * flush that closing the editor tab triggers.
   */
  protected const COMMIT_WAIT_SECONDS = 20;
  protected const COMMIT_POLL_MICROSECONDS = 1500000;

  /**
   * Re-read the drive item until its cTag moves, or the wait runs out.
   *
   * Returns the latest item either way — an unchanged cTag after the full wait
   * is a genuine "the author edited nothing", not a race.
   *
   * ⚠ This deliberately blocks the request for up to COMMIT_WAIT_SECONDS. The
   * alternative is what we had before: returning instantly with the wrong
   * answer and silently writing a stale file over the author's work.
   */
  protected function waitForCommit(array $session): array {
    $item = $this->graph->getItem($session['drive_id'], $session['item_id']);

    // Nothing to compare against, so there is nothing to wait for.
    if (($session['c_tag'] ?? '') === '') {
      return $item;
    }

    $deadline = time() + self::COMMIT_WAIT_SECONDS;

    while ((string) ($item['cTag'] ?? '') === $session['c_tag'] && time() < $deadline) {
      usleep(self::COMMIT_POLL_MICROSECONDS);
      $item = $this->graph->getItem($session['drive_id'], $session['item_id']);
    }

    if ((string) ($item['cTag'] ?? '') === $session['c_tag']) {
      $this->getLogger('m365')->info(
        'Template @item still unchanged after waiting @secs s; treating as no edits.',
        ['@item' => $session['item_id'], '@secs' => self::COMMIT_WAIT_SECONDS]
      );
    }

    return $item;
  }

  /**
   * Where the returned .docx should be written.
   *
   * Reuses the directory the node's current template already lives in, so
   * templates do not scatter across the files tree as this feature is adopted.
   */
  protected function destinationDirectory(Node $node): string {
    $directory = 'public://template_uploads';

    $value = $node->get('field_import_template')->getValue();
    if (!empty($value[0]['target_id'])) {
      $existing = $this->entityTypeManager()->getStorage('file')->load($value[0]['target_id']);
      if ($existing) {
        $dir = $this->fileSystem->dirname($existing->getFileUri());
        if ($dir !== '') {
          $directory = $dir;
        }
      }
    }

    $this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    );

    return $directory;
  }

}
