<?php

namespace Drupal\associatedps_affidavit_template\Controller;

use Drupal\associatedps_affidavit_template\Service\WordAffidavitGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\file\FileInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Generates affidavits in-house, merging a .docx template with Job values.
 *
 * This is the entry point for Generate → Affidavit. It resolves which Library
 * template the Job should use and merges it here. A .docx authored in Word for
 * the web is the only kind it can merge.
 *
 * ⚠⚠ 2026-09-28 — IT NO LONGER DEFERS TO DOCUSIGN. It used to hand anything it
 * could not merge to DocuSignController so old templates kept working. DocuSign
 * is retired, so a job still pointing at one of its templates now gets a message
 * telling the user to replace the template, and no DocuSign call is made at all.
 * See cannotGenerateMessage().
 *
 * ⚠ The document walk below is a second copy of the one in
 * DocuSignController::generateViaDocuSign(). That controller is on its way out,
 * so the duplication resolves itself when it goes; until then do not wire the
 * two together. Precedence here: manual selection, then the PRIMARY document,
 * then the first document carrying a usable template, all filtered by category.
 */
class WordTemplateController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Identifies this generation method, alongside 'tabs' and 'docgen'.
   */
  const METHOD = 'word';

  protected WordAffidavitGenerator $generator;

  public function __construct(WordAffidavitGenerator $generator) {
    $this->generator = $generator;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('associatedps_affidavit_template.word_affidavit_generator')
    );
  }

  /**
   * Generate an Affidavit for a Job.
   *
   * Route: /word-template/generate-affidavit/{jobId}
   */
  public function generateAffidavit($jobId, Request $request) {
    return $this->generate((int) $jobId, 'Affidavit', $request);
  }

  /**
   * Generate a Correspondence document for a Job.
   *
   * Route: /word-template/generate-correspondence/{jobId}
   */
  public function generateCorrespondence($jobId, Request $request) {
    return $this->generate((int) $jobId, 'Correspondence', $request);
  }

  /**
   * Resolve a template and merge it, or explain why it cannot be merged.
   */
  protected function generate(int $jobId, string $category, Request $request): JsonResponse {
    $job = Node::load($jobId);
    if (!$job || $job->bundle() !== 'job') {
      return new JsonResponse(['error' => 'Invalid Job node.'], 400);
    }

    $manualNid = $request->query->get('library_nid');
    $library = $this->resolveLibrary($job, $category, $manualNid);

    // ⚠⚠ NO HAND-OFF TO DOCUSIGN. This used to call DocuSignController so that
    // tab and doc-gen templates kept working; DocuSign is now retired, so
    // opening its editor would start a process nobody can finish. Say what is
    // wrong instead, and start nothing.
    if (!$library) {
      return new JsonResponse([
        'error' => $this->cannotGenerateMessage($job, $category, $manualNid),
      ], 422);
    }

    try {
      // An Affidavit is saved as private://affidavits/[job number].docx, so the
      // download icon beside "Affidavit Drafted" can find it later; generating
      // again REPLACES it. Correspondence keeps the dated, never-overwritten
      // name — sharing the fixed name would overwrite the job's affidavit.
      $destination = $category === 'Affidavit' ? $this->affidavitUri($job) : NULL;
      $file = $this->generator->generate($job, $library, [], $destination);
    }
    catch (\RuntimeException $e) {
      // The message is written for the staff member — a malformed placeholder,
      // a missing file, or the service being down.
      return new JsonResponse(['error' => $e->getMessage()], 422);
    }
    catch (\Throwable $e) {
      $this->getLogger('word_docgen')->error('Affidavit generation failed for job @job: @msg', [
        '@job' => $jobId,
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'The document could not be generated.'], 500);
    }

    return new JsonResponse([
      'method' => self::METHOD,
      'fileId' => $file->id(),
      'fileName' => $file->getFilename(),
      'libraryNid' => $library->id(),
      'libraryTitle' => $library->label(),
      'downloadUrl' => $this->downloadUrl($file),
    ]);
  }

  /**
   * Which Library template this Job should use, if this flow can handle it.
   *
   * Precedence, matching the DocuSign flow (see the class docblock warning):
   *   1. a template the user picked explicitly from the modal;
   *   2. the PRIMARY document's template, which wins outright;
   *   3. the first document, in paragraph order, carrying a usable template.
   *
   * @return \Drupal\node\NodeInterface|null
   *   NULL means "not ours" — either no template at all, or an old DocuSign one.
   *   findLegacyTemplate() then tells the two apart for the error message.
   */
  protected function resolveLibrary(NodeInterface $job, string $category, $manualNid = NULL): ?NodeInterface {
    if (!empty($manualNid)) {
      $candidate = Node::load($manualNid);
      if ($candidate && $candidate->bundle() === 'library' && $this->isWordTemplate($candidate)) {
        return $candidate;
      }
      // Explicitly chosen but not ours — defer, rather than silently ignoring
      // the choice and picking a different template.
      return NULL;
    }

    if (!$job->hasField('field_documents')) {
      return NULL;
    }

    $first = NULL;

    foreach ($job->get('field_documents') as $docGroup) {
      $paragraph = $docGroup->entity;
      if (!$paragraph) {
        continue;
      }

      $isPrimary = $paragraph->hasField('field_primary_document')
        && (int) $paragraph->get('field_primary_document')->value === 1;

      foreach ($paragraph->get('field_document') as $docItem) {
        $document = $docItem->entity;
        if (!$document || !$document->hasField('field_affidavit_template')) {
          continue;
        }

        $templateId = $document->field_affidavit_template->target_id ?? NULL;
        if (empty($templateId)) {
          continue;
        }

        $candidate = Node::load($templateId);
        if (!$candidate || $candidate->bundle() !== 'library') {
          continue;
        }
        if ($candidate->hasField('field_category') && $candidate->field_category->value !== $category) {
          continue;
        }
        if (!$this->isWordTemplate($candidate)) {
          continue;
        }

        if ($first === NULL) {
          $first = $candidate;
        }
        if ($isPrimary) {
          return $candidate;
        }
      }
    }

    return $first;
  }

  /**
   * List the templates a job may be generated from, and which one is its own.
   *
   * Route: /word-template/templates/{category}/{jobId}
   *
   * Modelled on DocuSignController::listTemplatesByCategory(), which does the
   * same job for the flow being retired. That method is left exactly as it is;
   * this one differs in what counts as available:
   *   - there: a Library item carrying a DocuSign template ID;
   *   - here: a Library item carrying a .docx we can actually merge.
   *
   * Templates without a .docx — the old DocuSign ones — are left out entirely,
   * so the list cannot offer something that would fail on generate.
   *
   * `defaultNid` is the job's own template by the same precedence generate uses
   * (primary document first, then the first document). It is what the picker
   * pre-selects, so confirming without changing anything generates exactly what
   * pressing Generate used to. It is NULL when the job has no usable template,
   * and the picker then waits for a choice.
   */
  public function listTemplates($category, $jobId): JsonResponse {
    $storage = $this->entityTypeManager()->getStorage('node');

    $nids = $storage->getQuery()
      ->condition('type', 'library')
      ->condition('field_category', $category)
      ->exists('field_import_template')
      ->accessCheck(TRUE)
      ->sort('title', 'ASC')
      ->execute();

    $templates = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      if (!$this->isWordTemplate($node)) {
        continue;
      }
      $file = $node->get('field_import_template')->entity;
      $templates[] = [
        'nid' => (int) $node->id(),
        'title' => $node->getTitle(),
        // Shown under the title: two templates can share a name, and the
        // document is what the author actually recognises.
        'fileName' => $file instanceof FileInterface ? $file->getFilename() : '',
      ];
    }

    $defaultNid = NULL;
    $job = Node::load($jobId);
    if ($job && $job->bundle() === 'job') {
      $default = $this->resolveLibrary($job, $category);
      if ($default) {
        $defaultNid = (int) $default->id();
      }
    }

    return new JsonResponse([
      'templates' => $templates,
      'defaultNid' => $defaultNid,
    ]);
  }

  /**
   * Why this job cannot be generated, written for the staff member.
   *
   * Two quite different situations reach here, and telling them apart is the
   * whole point: a job still pointing at an old DocuSign template needs the
   * template replaced, whereas a job with nothing assigned just needs one
   * chosen. A single vague message would send people looking in the wrong place.
   */
  protected function cannotGenerateMessage(NodeInterface $job, string $category, $manualNid = NULL): string {
    $legacy = $this->findLegacyTemplate($job, $category, $manualNid);

    if ($legacy) {
      return 'The template assigned to this job — "' . $legacy->label() . '" — is an older '
        . 'DocuSign template and can no longer be opened. Please delete this template and '
        . 're-assign the job with a new template created with Microsoft Word (Microsoft 365).';
    }

    return 'No ' . $category . ' template is assigned to this job. Please assign a template '
      . 'created with Microsoft Word (Microsoft 365) to the job\'s primary document.';
  }

  /**
   * The first template on this job that this flow cannot merge, if any.
   *
   * Deliberately the same walk as resolveLibrary(), with the .docx test
   * INVERTED: it finds what resolveLibrary() rejected, which is what makes the
   * "older DocuSign template" message specific enough to act on. Category is
   * still honoured, so pressing Affidavit on a job holding only a Correspondence
   * template reports "no Affidavit template" rather than blaming DocuSign.
   */
  protected function findLegacyTemplate(NodeInterface $job, string $category, $manualNid = NULL): ?NodeInterface {
    if (!empty($manualNid)) {
      $candidate = Node::load($manualNid);
      return ($candidate && $candidate->bundle() === 'library' && !$this->isWordTemplate($candidate))
        ? $candidate
        : NULL;
    }

    if (!$job->hasField('field_documents')) {
      return NULL;
    }

    foreach ($job->get('field_documents') as $docGroup) {
      $paragraph = $docGroup->entity;
      if (!$paragraph) {
        continue;
      }

      foreach ($paragraph->get('field_document') as $docItem) {
        $document = $docItem->entity;
        if (!$document || !$document->hasField('field_affidavit_template')) {
          continue;
        }

        $templateId = $document->field_affidavit_template->target_id ?? NULL;
        if (empty($templateId)) {
          continue;
        }

        $candidate = Node::load($templateId);
        if (!$candidate || $candidate->bundle() !== 'library') {
          continue;
        }
        if ($candidate->hasField('field_category') && $candidate->field_category->value !== $category) {
          continue;
        }
        if (!$this->isWordTemplate($candidate)) {
          return $candidate;
        }
      }
    }

    return NULL;
  }

  /**
   * Whether a Library item is one this flow merges.
   *
   * ⭐ The file extension IS the routing signal — there is no marker field.
   * Verified across all 40 Library items: those with a .docx carry no DocuSign
   * ID and vice versa, so the two rules cannot disagree. Deliberate: a new
   * field would mean a config export, and config drift between local and
   * staging has bitten this project repeatedly.
   */
  protected function isWordTemplate(NodeInterface $library): bool {
    if (!$library->hasField('field_import_template') || $library->get('field_import_template')->isEmpty()) {
      return FALSE;
    }

    $file = $library->get('field_import_template')->entity;
    if (!$file instanceof FileInterface) {
      return FALSE;
    }

    return strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION)) === 'docx';
  }

  /**
   * Serve a generated affidavit.
   *
   * Route: /word-template/download/{fid}
   *
   * ⚠ These live in private://, which Apache cannot serve, so every download
   * comes through here and is permission-checked.
   */
  public function download($fid) {
    $file = $this->entityTypeManager()->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $uri = $file->getFileUri();
    // Only ever serve from the generated-affidavit directory — this route must
    // not become a way to read arbitrary private files by guessing an fid.
    if (strpos($uri, 'private://affidavits/') !== 0) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $path = \Drupal::service('file_system')->realpath($uri);
    if (!$path || !file_exists($path)) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $response = new BinaryFileResponse($path);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $file->getFilename()
    );
    $response->headers->set(
      'Content-Type',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    );

    return $response;
  }

  /**
   * Whether the job's affidavit ([job number].docx) has been generated.
   *
   * Route: /affidavit-document/{jobId}/status — the download icon beside
   * "Affidavit Drafted". Same response shape as the invoice document's status,
   * so one script drives both icons.
   */
  public function affidavitStatus($jobId): JsonResponse {
    $job = $this->loadEditableJob($jobId);
    try {
      $path = \Drupal::service('file_system')->realpath($this->affidavitUri($job));
    }
    catch (\RuntimeException $e) {
      return new JsonResponse(['error' => $e->getMessage()], 422);
    }
    $exists = $path && is_file($path);

    return new JsonResponse([
      'exists' => $exists,
      'fileName' => $exists ? basename($path) : NULL,
      'downloadUrl' => $exists
        ? \Drupal\Core\Url::fromRoute('associatedps_affidavit_template.affidavit_document_download', ['jobId' => $job->id()])->setAbsolute()->toString()
        : NULL,
    ]);
  }

  /**
   * Serve the job's saved affidavit ([job number].docx).
   *
   * Route: /affidavit-document/{jobId}/download — addressed by job, so the
   * only file it can serve is the one named after this job's number.
   */
  public function affidavitDownload($jobId) {
    $job = $this->loadEditableJob($jobId);
    try {
      $path = \Drupal::service('file_system')->realpath($this->affidavitUri($job));
    }
    catch (\RuntimeException $e) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }
    if (!$path || !is_file($path)) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $response = new BinaryFileResponse($path);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($path));
    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    return $response;
  }

  /**
   * private://affidavits/[job number].docx — the Job title IS the Job Number.
   *
   * One file per job: every Generate Affidavit (button or icon) REPLACES it,
   * so the server always holds the latest affidavit (user decision
   * 2026-10-15 — regenerating is allowed at any time).
   *
   * Characters outside what both Windows and Linux accept in a file name are
   * replaced (local and staging are different OSes).
   */
  protected function affidavitUri(NodeInterface $job): string {
    $safe = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $job->label()), '-.');
    if ($safe === '') {
      throw new \RuntimeException('This job has no Job Number, so the affidavit cannot be saved. Enter the Job Number and save the job first.');
    }
    return 'private://affidavits/' . $safe . '.docx';
  }

  /**
   * The Job, if it exists and the user may edit it (the icon lives on the edit
   * form, and the document carries party names and addresses).
   */
  protected function loadEditableJob($jobId): NodeInterface {
    $job = Node::load((int) $jobId);
    if (!$job || $job->bundle() !== 'job') {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }
    if (!$job->access('update')) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }
    return $job;
  }

  /**
   * Absolute URL for the download route.
   */
  protected function downloadUrl(FileInterface $file): string {
    return \Drupal\Core\Url::fromRoute(
      'associatedps_affidavit_template.word_download',
      ['fid' => $file->id()]
    )->setAbsolute()->toString();
  }

}
