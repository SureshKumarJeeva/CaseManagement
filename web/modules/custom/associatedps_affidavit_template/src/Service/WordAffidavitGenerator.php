<?php

namespace Drupal\associatedps_affidavit_template\Service;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\node\NodeInterface;

/**
 * Generates a merged affidavit for a Job from a Library .docx template.
 *
 * Ties the three existing pieces together: the Library's authored template,
 * DocuSignTokenMapper's resolved values, and the Python merge service. The
 * result is stored as a managed file in private:// and handed back.
 *
 * ⚠ WHY private:// AND NOT public://: a blank template carries nothing
 * sensitive, but a MERGED affidavit carries party names, service addresses and
 * case details. private:// puts it outside the docroot, so Apache cannot serve
 * it directly and every download passes through Drupal's access checks.
 */
class WordAffidavitGenerator {

  /**
   * Where merged affidavits are written.
   *
   * Kept separate from public://template_uploads (the blank templates) so the
   * two are never confused by a cleanup routine or a backup rule.
   */
  protected const DESTINATION_DIR = 'private://affidavits';

  protected WordMergeClient $merge;
  protected DocuSignTokenMapper $tokenMapper;
  protected FileRepositoryInterface $fileRepository;
  protected FileSystemInterface $fileSystem;
  protected LoggerChannelInterface $logger;

  public function __construct(
    WordMergeClient $merge,
    DocuSignTokenMapper $token_mapper,
    FileRepositoryInterface $file_repository,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->merge = $merge;
    $this->tokenMapper = $token_mapper;
    $this->fileRepository = $file_repository;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('word_docgen');
  }

  /**
   * Generate the affidavit for a Job against a Library template.
   *
   * @param \Drupal\node\NodeInterface $job
   *   The Job node supplying the values.
   * @param \Drupal\node\NodeInterface $library
   *   The Library node whose field_import_template holds the .docx.
   * @param array $extra
   *   Optional values merged on top of the Job's, used by the invoice document
   *   (Xero's Invoice.* values and Invoice_Lines rows). Keys, all optional:
   *   'tokens' (list of ['name' => ..., 'value' => ...], appended), 'tables'
   *   and 'lists' (keyed by name, added/overriding). Affidavit and
   *   Correspondence pass nothing.
   * @param string|null $destination
   *   Optional exact URI to save to, REPLACING any file already there — the
   *   invoice document is looked up by its fixed name
   *   (private://invoices/[invoice_number].docx). NULL keeps the affidavit
   *   behaviour: a dated, uniquely named file that never overwrites another.
   *
   * @return \Drupal\file\FileInterface
   *   The stored merged document.
   *
   * @throws \RuntimeException
   *   Message is written to be shown to the staff member.
   */
  public function generate(NodeInterface $job, NodeInterface $library, array $extra = [], ?string $destination = NULL): FileInterface {
    $template = $this->templateFile($library);
    $bytes = $this->readTemplate($template);

    $tokens = $this->tokenMapper->resolveTokensForJob($job);
    if (empty($tokens)) {
      throw new \RuntimeException('No field values could be read from this job.');
    }
    foreach ($extra['tokens'] ?? [] as $pair) {
      $tokens[] = $pair;
    }

    // Rows for `{%tr for %}` loops. Templates that use none are unaffected —
    // the merge service simply never looks at them.
    $tables = $this->tokenMapper->resolveTableRowsForJob($job);

    // One-column lists off those same rows. These override the flat pairs for
    // any name they cover, which is the point: the flat
    // Documents.Affidavit_Template is empty and Documents.Document_Title is a
    // run-on of every document's whole rendered line. See
    // DocuSignTokenMapper::resolveListTokensForJob().
    $lists = $this->tokenMapper->resolveListTokensForJob($job);

    $tables = ($extra['tables'] ?? []) + $tables;
    $lists = ($extra['lists'] ?? []) + $lists;

    $merged = $this->merge->render($bytes, $tokens, $tables, $lists, $template->getFilename());

    return $this->store($merged, $job, $library, $destination);
  }

  /**
   * The .docx on a Library node, or NULL if it has none.
   *
   * Also the routing test: a Library whose template is a .docx belongs to this
   * flow, and one with a .doc, .rtf or .pdf does not.
   */
  public function templateFile(NodeInterface $library): FileInterface {
    if (!$library->hasField('field_import_template') || $library->get('field_import_template')->isEmpty()) {
      throw new \RuntimeException('This template has no document attached. Upload a .docx to the Library item first.');
    }

    $file = $library->get('field_import_template')->entity;
    if (!$file instanceof FileInterface) {
      throw new \RuntimeException('The template file attached to this Library item is missing.');
    }

    $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    if ($extension !== 'docx') {
      throw new \RuntimeException(sprintf(
        'This template is a .%s file. Affidavit generation needs a .docx — open the Library item and re-author the template in Word.',
        $extension
      ));
    }

    return $file;
  }

  /**
   * Read a template's bytes off disk.
   */
  protected function readTemplate(FileInterface $file): string {
    $uri = $file->getFileUri();
    $bytes = @file_get_contents($uri);

    if ($bytes === FALSE || $bytes === '') {
      $this->logger->error('Template file missing on disk: @uri', ['@uri' => $uri]);
      throw new \RuntimeException('The template file could not be read from the server.');
    }

    return $bytes;
  }

  /**
   * Write the merged document into private:// as a managed file.
   */
  protected function store(string $bytes, NodeInterface $job, NodeInterface $library, ?string $destination = NULL): FileInterface {
    // A fixed destination (the invoice document) is written at exactly that
    // name; otherwise a dated subdirectory — these accumulate one per
    // generation and a flat directory of thousands of files is painful to work
    // with on the server.
    $directory = $destination !== NULL
      ? $this->fileSystem->dirname($destination)
      : self::DESTINATION_DIR . '/' . date('Y-m');

    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $this->logger->error('Could not create @dir', ['@dir' => $directory]);
      throw new \RuntimeException('The generated document could not be saved on the server.');
    }

    if ($destination !== NULL) {
      // The file name IS the lookup key, so the fixed name must be kept.
      $file = $this->fileRepository->writeData($bytes, $destination, FileExists::Replace);
    }
    else {
      // Rename rather than Replace: regenerating an affidavit must not silently
      // destroy the previous one, which may already have been sent or filed.
      $file = $this->fileRepository->writeData($bytes, $directory . '/' . $this->filename($job, $library), FileExists::Rename);
    }

    $this->logger->info('Generated document @fid (@uri) for job @job from library @lib (@bytes bytes)', [
      '@uri' => $file->getFileUri(),
      '@fid' => $file->id(),
      '@job' => $job->id(),
      '@lib' => $library->id(),
      '@bytes' => strlen($bytes),
    ]);

    return $file;
  }

  /**
   * A filename a person can recognise in a folder listing.
   */
  protected function filename(NodeInterface $job, NodeInterface $library): string {
    $parts = [
      $job->label() ?: ('job-' . $job->id()),
      $library->label() ?: 'affidavit',
      date('Ymd-His'),
    ];

    $name = implode('_', $parts);
    // Windows and Linux disagree on what is legal in a filename; this keeps to
    // the intersection, since staging and local are not the same OS.
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'affidavit';

    return trim($name, '-') . '.docx';
  }

}
