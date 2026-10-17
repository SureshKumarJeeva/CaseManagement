<?php

namespace Drupal\associatedps_affidavit_template\Controller;

use Drupal\associatedps_affidavit_template\Service\DocuSignTokenMapper;
use Drupal\associatedps_affidavit_template\Service\WordAffidavitGenerator;
use Drupal\associatedps_affidavit_template\Service\XeroClient;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Job's invoice document, behind the download icon beside "Invoice
 * Drafted" (Job Status → Admin Status).
 *
 * Generate Invoice only creates the draft in Xero; staff may then change lines
 * and amounts there. So the document is produced on demand, from Xero's
 * CURRENT copy of the invoice, the first time the icon is clicked:
 *
 *   status    — is private://invoices/[invoice_number].docx already there?
 *               yes → the JS downloads it straight away;
 *   templates — no → the JS shows the Invoice template picker;
 *   generate  — read the invoice from Xero, merge, save as [number].docx;
 *   download  — serve that file (every later click comes straight here).
 *
 * The merge itself is WordAffidavitGenerator's, with Xero's values passed as
 * $extra and the fixed file name as $destination.
 *
 * ⚠ Xero unavailable → generation STOPS with an error (user decision
 * 2026-10-15): an invoice document without Xero's amounts would mislead.
 * ⏳ Pending: a "Regenerate" option to replace a saved document.
 */
class InvoiceDocumentController extends ControllerBase {

  /**
   * The Library category of invoice templates.
   */
  const CATEGORY = 'Invoice';

  /**
   * Where invoice documents are written. private:// — they carry amounts,
   * client details and party names.
   */
  const DESTINATION_DIR = 'private://invoices';

  /**
   * Invoice.* tokens, child name => label. The single source for both the
   * Library token panel and the merge payload, so the two cannot drift.
   *
   * ⚠ Child names stay Ucfirst — the merge service asserts that no child name
   * shadows a Python str method (all lowercase).
   */
  const INVOICE_FIELDS = [
    'Number' => 'Invoice Number',
    'Date' => 'Invoice Date',
    'Due_Date' => 'Due Date',
    'Reference' => 'Reference',
    'Status' => 'Status',
    'Contact' => 'Contact (billed to)',
    'Currency' => 'Currency',
    'Sub_Total' => 'Subtotal',
    'Total_Tax' => 'GST / Total Tax',
    'Total' => 'Total',
    'Amount_Paid' => 'Amount Paid',
    'Amount_Due' => 'Amount Due',
  ];

  /**
   * Invoice_Lines row columns, child name => label.
   */
  const LINE_FIELDS = [
    'Description' => 'Description',
    'Quantity' => 'Quantity',
    'Unit_Amount' => 'Unit Price',
    'Discount_Rate' => 'Discount %',
    'Tax_Amount' => 'Tax Amount',
    'Line_Amount' => 'Amount',
    'Account_Code' => 'Account Code',
    'Item_Code' => 'Item Code',
  ];

  protected WordAffidavitGenerator $generator;
  protected XeroClient $xero;

  public function __construct(WordAffidavitGenerator $generator, XeroClient $xero) {
    $this->generator = $generator;
    $this->xero = $xero;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('associatedps_affidavit_template.word_affidavit_generator'),
      $container->get('associatedps_affidavit_template.xero_client')
    );
  }

  /**
   * Whether the document already exists, and the invoice number it is for.
   *
   * Route: /invoice-document/{jobId}/status
   */
  public function status($jobId): JsonResponse {
    $job = $this->loadJob($jobId);
    $number = $this->invoiceNumber($job);
    if ($number === '') {
      return new JsonResponse(['error' => $this->noNumberMessage()], 422);
    }

    try {
      $exists = $this->documentExists($number);
    }
    catch (\RuntimeException $e) {
      return new JsonResponse(['error' => $e->getMessage()], 422);
    }

    return new JsonResponse([
      'invoiceNumber' => $number,
      'exists' => $exists,
      'downloadUrl' => $exists ? $this->downloadUrl($job) : NULL,
    ]);
  }

  /**
   * Invoice templates for the picker, most recently updated first.
   *
   * Route: /invoice-document/{jobId}/templates
   *
   * Same response shape as WordTemplateController::listTemplates(), so the
   * picker reads them the same way; `defaultNid` is the latest-updated one.
   */
  public function templates($jobId): JsonResponse {
    $this->loadJob($jobId);
    $storage = $this->entityTypeManager()->getStorage('node');

    $nids = $storage->getQuery()
      ->condition('type', 'library')
      ->condition('field_category', self::CATEGORY)
      ->exists('field_import_template')
      ->accessCheck(TRUE)
      ->sort('changed', 'DESC')
      ->execute();

    $templates = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $file = $node->get('field_import_template')->entity;
      // Only a .docx can be merged.
      if (!$file instanceof FileInterface || strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION)) !== 'docx') {
        continue;
      }
      $templates[] = [
        'nid' => (int) $node->id(),
        'title' => $node->getTitle(),
        'fileName' => $file->getFilename(),
      ];
    }

    return new JsonResponse([
      'templates' => $templates,
      'defaultNid' => $templates ? $templates[0]['nid'] : NULL,
    ]);
  }

  /**
   * Merge the invoice document from Xero's current invoice and save it.
   *
   * Route: /invoice-document/{jobId}/generate?library_nid=N
   */
  public function generate($jobId, Request $request): JsonResponse {
    $job = $this->loadJob($jobId);
    $number = $this->invoiceNumber($job);
    if ($number === '') {
      return new JsonResponse(['error' => $this->noNumberMessage()], 422);
    }

    $library = Node::load((int) $request->query->get('library_nid'));
    if (!$library || $library->bundle() !== 'library' || $library->get('field_category')->value !== self::CATEGORY) {
      return new JsonResponse(['error' => 'Please choose an Invoice template.'], 422);
    }

    try {
      // Template checked before Xero, so a broken template costs no Xero call.
      $this->generator->templateFile($library);

      // The invoice as it stands in Xero NOW. No Xero, no document.
      $invoice = $this->xero->getInvoiceByNumber($number);
      if (isset($invoice['error'])) {
        throw new \RuntimeException($invoice['error']);
      }

      $file = $this->generator->generate($job, $library, $this->xeroValues($invoice), $this->fileUri($number));
    }
    catch (\RuntimeException $e) {
      return new JsonResponse(['error' => $e->getMessage()], 422);
    }
    catch (\Throwable $e) {
      $this->getLogger('invoice_docgen')->error('Invoice document failed for job @job: @msg', [
        '@job' => $jobId,
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'The invoice document could not be generated.'], 500);
    }

    return new JsonResponse([
      'invoiceNumber' => $number,
      'fileName' => $file->getFilename(),
      'libraryTitle' => $library->label(),
      'downloadUrl' => $this->downloadUrl($job),
    ]);
  }

  /**
   * Serve the saved invoice document.
   *
   * Route: /invoice-document/{jobId}/download
   *
   * Addressed by job, not file id: the only file it can ever serve is the one
   * named after this job's saved invoice number.
   */
  public function download($jobId) {
    $job = $this->loadJob($jobId);
    $number = $this->invoiceNumber($job);
    if ($number === '') {
      throw new NotFoundHttpException();
    }

    $path = \Drupal::service('file_system')->realpath($this->fileUri($number));
    if (!$path || !is_file($path)) {
      throw new NotFoundHttpException();
    }

    $response = new BinaryFileResponse($path);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($path));
    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    return $response;
  }

  /**
   * Invoice token options for the Library token panel ([{label, value}]).
   */
  public static function tokenOptions(DocuSignTokenMapper $mapper): array {
    $options = [];
    foreach (self::INVOICE_FIELDS as $child => $label) {
      $options[] = [
        'label' => 'Invoice (Xero)-' . $label,
        'value' => $mapper->toJinjaToken('Invoice.' . $child),
      ];
    }
    foreach (self::LINE_FIELDS as $child => $label) {
      $options[] = [
        'label' => 'Invoice (Xero)-Invoice Lines-' . $label,
        'value' => $mapper->toJinjaToken('Invoice_Lines.' . $child),
      ];
    }
    return $options;
  }

  /**
   * The Job, if it exists and the user may edit it.
   *
   * Edit access, not just view: the document carries the invoice amounts, and
   * the icon lives on the edit form.
   */
  protected function loadJob($jobId): NodeInterface {
    $job = Node::load((int) $jobId);
    if (!$job || $job->bundle() !== 'job') {
      throw new NotFoundHttpException();
    }
    if (!$job->access('update')) {
      throw new AccessDeniedHttpException();
    }
    return $job;
  }

  /**
   * The Job's SAVED invoice number ('' when none).
   *
   * Saved, not form value: Generate Invoice writes the number into the open
   * form, and it only becomes the Job's once staff press Save.
   */
  protected function invoiceNumber(NodeInterface $job): string {
    return $job->hasField('field_invoice_number') ? trim((string) $job->get('field_invoice_number')->value) : '';
  }

  protected function noNumberMessage(): string {
    return 'This job has no saved invoice number. Generate the invoice in Xero, then save the job, before downloading the invoice document.';
  }

  /**
   * private://invoices/[invoice_number].docx.
   *
   * Characters outside what both Windows and Linux accept in a file name are
   * replaced (local and staging are different OSes).
   */
  protected function fileUri(string $number): string {
    $safe = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $number), '-.');
    if ($safe === '') {
      throw new \RuntimeException('The invoice number on this job cannot be used as a file name.');
    }
    return self::DESTINATION_DIR . '/' . $safe . '.docx';
  }

  protected function documentExists(string $number): bool {
    $path = \Drupal::service('file_system')->realpath($this->fileUri($number));
    return $path && is_file($path);
  }

  protected function downloadUrl(NodeInterface $job): string {
    return Url::fromRoute('associatedps_affidavit_template.invoice_document_download', ['jobId' => $job->id()])
      ->setAbsolute()->toString();
  }

  /**
   * Xero's invoice → the $extra payload for WordAffidavitGenerator::generate().
   */
  protected function xeroValues(array $invoice): array {
    $values = [
      'Number' => (string) ($invoice['InvoiceNumber'] ?? ''),
      'Date' => $this->xeroDate($invoice['DateString'] ?? ''),
      'Due_Date' => $this->xeroDate($invoice['DueDateString'] ?? ''),
      'Reference' => (string) ($invoice['Reference'] ?? ''),
      'Status' => (string) ($invoice['Status'] ?? ''),
      'Contact' => (string) ($invoice['Contact']['Name'] ?? ''),
      'Currency' => (string) ($invoice['CurrencyCode'] ?? ''),
      'Sub_Total' => $this->money($invoice['SubTotal'] ?? NULL),
      'Total_Tax' => $this->money($invoice['TotalTax'] ?? NULL),
      'Total' => $this->money($invoice['Total'] ?? NULL),
      'Amount_Paid' => $this->money($invoice['AmountPaid'] ?? NULL),
      'Amount_Due' => $this->money($invoice['AmountDue'] ?? NULL),
    ];

    $tokens = [];
    foreach ($values as $child => $value) {
      $tokens[] = ['name' => 'Invoice.' . $child, 'value' => $value];
    }

    $rows = [];
    foreach ($invoice['LineItems'] ?? [] as $line) {
      $rows[] = [
        'Description' => (string) ($line['Description'] ?? ''),
        'Quantity' => $this->number($line['Quantity'] ?? NULL),
        'Unit_Amount' => $this->money($line['UnitAmount'] ?? NULL),
        'Discount_Rate' => $this->number($line['DiscountRate'] ?? NULL),
        'Tax_Amount' => $this->money($line['TaxAmount'] ?? NULL),
        'Line_Amount' => $this->money($line['LineAmount'] ?? NULL),
        'Account_Code' => (string) ($line['AccountCode'] ?? ''),
        'Item_Code' => (string) ($line['ItemCode'] ?? ''),
      ];
    }

    $extra = ['tokens' => $tokens];
    if ($rows) {
      // Repeating rows for {%tr for %} / {%p for %} blocks...
      $extra['tables'] = ['Invoice_Lines' => $rows];
      // ...and one-paragraph-per-line lists, so a plain
      // {{ Invoice_Lines.Description }} lists every line, as the Job's own
      // table fields do.
      foreach (array_keys(self::LINE_FIELDS) as $child) {
        $extra['lists']['Invoice_Lines.' . $child] = array_column($rows, $child);
      }
    }
    return $extra;
  }

  /**
   * Two decimals with thousands separators; '' for no value.
   */
  protected function money($amount): string {
    return is_numeric($amount) ? number_format((float) $amount, 2) : '';
  }

  /**
   * A plain number without trailing zeros ("2", "12.5"); '' for no value.
   */
  protected function number($value): string {
    if (!is_numeric($value)) {
      return '';
    }
    return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
  }

  /**
   * Xero's "2026-10-15T00:00:00" → "2026-10-15".
   *
   * Date part only, deliberately: the merge client then formats it like every
   * other date and, being date-only, does not shift it a timezone.
   */
  protected function xeroDate(string $value): string {
    return preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) ? $m[1] : '';
  }

}
