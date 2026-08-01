<?php

namespace Drupal\associatedps_affidavit_template\Service;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\associatedps_affidavit_template\Service\XeroClient;

class XeroInvoiceIntegration {

    /**
     * En dash used in the invoice Reference block and line descriptions,
     * matching the APS Xero template spec ("Job Type – Suburb", "Fee – Name").
     */
    private const EN_DASH = "\u{2013}";

    protected Connection $database;
    /**
     * Xero client.
     *
     * @var \Drupal\associatedps_affidavit_template\Service\XeroClient
     */
    protected XeroClient $xeroService;

    protected XeroContactStore $contactStore;

    protected EntityTypeManagerInterface $entityTypeManager;

     /**
     * Constructor.
     */
    public function __construct(
        XeroClient $xero_service,
        Connection $database,
        XeroContactStore $contact_store,
        EntityTypeManagerInterface $entity_type_manager
    ) {
        $this->xeroService = $xero_service;
        $this->database = $database;
        $this->contactStore = $contact_store;
        $this->entityTypeManager = $entity_type_manager;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container): static {
        return new static(
            $container->get('associatedps_affidavit_template.xero_client'),
            $container->get('database'),
            $container->get('associatedps_affidavit_template.xero_contact_store'),
            $container->get('entity_type.manager')
        );
    }

    /**
     * Creates an invoice in Xero for the Client referenced by a Job.
     *
     * The Job's field_client is the source of truth — its Xero ContactID
     * (recorded by the contact sync) routes the invoice to the right Xero
     * contact. If the client has never been synced, the caller is told to
     * sync first rather than guessing.
     */
    public function createInvoice($jobId): array {
        \Drupal::logger('associatedps_affidavit_template_Xero')->info('Creating invoice for Job ID: @jobId', ['@jobId' => $jobId]);
        try{
            $job = $this->entityTypeManager->getStorage('node')->load($jobId);
            if (!$job || $job->bundle() !== 'job') {
                return ['error' => 'Job node ' . $jobId . ' not found.'];
            }
            if (!$job->hasField('field_client') || $job->get('field_client')->isEmpty()) {
                return ['error' => 'Job ' . $jobId . ' has no client assigned — cannot generate invoice.'];
            }
            $client_target = $job->get('field_client')->first()->getValue();
            $client_nid = (int) ($client_target['target_id'] ?? 0);
            if ($client_nid <= 0) {
                return ['error' => 'Job ' . $jobId . ' has an invalid client reference.'];
            }

            $contact_id = $this->contactStore->get($client_nid);
            if (!$contact_id) {
                return [
                    'error' => 'Client (node ' . $client_nid . ') is not yet synced to Xero. '
                        . 'Run Configuration → Xero Account → Sync Contacts, or re-save the client, then try again.',
                ];
            }

            // Build line items from the Job's job_fees paragraphs and apply
            // the client's agreed discount per line.
            $client = $this->entityTypeManager->getStorage('node')->load($client_nid);
            $discount_rate = 0;
            if ($client && $client->hasField('field_agreed_discount') && !$client->get('field_agreed_discount')->isEmpty()) {
                $discount_rate = (int) $client->get('field_agreed_discount')->value;
            }

            $line_items_result = $this->buildLineItemsFromJob($job, $discount_rate);
            if (isset($line_items_result['error'])) {
                return $line_items_result;
            }
            $line_items = $line_items_result['lines'];

            $now = new \DateTime();
            $currentDate = $now->format('Y-m-d');
            $dueDate = (clone $now)->modify('+30 days')->format('Y-m-d');

            // Composed header block (MATTER / Re: / Your & Our Reference). Xero
            // has one free-text Reference field, rendered by «Reference».
            $reference = $this->buildInvoiceReference($job);

            $invoice_payload = [
                'Invoices' => [
                  [
                    'Type' => 'ACCREC',
                    'Contact' => [
                        'ContactID' => $contact_id,
                    ],
                    'Date' => $currentDate,
                    'DueDate' => $dueDate,
                    'Reference' => $reference,
                    "LineAmountTypes" => "Exclusive",
                    'LineItems' => $line_items,
                    'Status' => 'DRAFT',
                  ],
                ],
            ];
            $json_invoiceID = $this->xeroService->generatePaidInvoice(json_encode($invoice_payload));
            if(isset($json_invoiceID['error'])){
                return ['error' => 'Unable to create invoice in Xero: ' . (string)$json_invoiceID['error']];
            }
            return [
                'message' => $json_invoiceID['message'],
                'invoiceId' => $json_invoiceID['invoiceId'],
                'invoiceNumber' => $json_invoiceID['invoiceNumber'] ?? '',
            ];
        }catch (\Exception $e) {
            \Drupal::logger('associatedps_affidavit_template_Xero')->error('Xero createInvoice error: @msg', ['@msg' => $e->getMessage()]);
            return ['error' => 'Unable to create invoice: ' . $e->getMessage()];
        }
    }

    /**
     * Builds Xero LineItems from a Job's field_job_fees paragraphs.
     *
     * - Description comes from the referenced "Type of Service" taxonomy term.
     * - Quantity = field_units (default 1 when empty).
     * - UnitAmount = field_agreed_fee, falling back to field_standard_fee.
     * - A line is skipped if both fees are empty/zero (recorded for the error).
     * - DiscountRate applied per line from the client's agreed discount (%).
     *
     * @return array
     *   ['lines' => LineItem[]] on success, or ['error' => string] when the
     *   job has no usable fee lines.
     */
    protected function buildLineItemsFromJob($job, int $discount_rate): array {
        if (!$job->hasField('field_job_fees') || $job->get('field_job_fees')->isEmpty()) {
            return ['error' => 'Job has no fee lines (field_job_fees is empty). Add Job Fees before generating an invoice.'];
        }

        $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
        $lines = [];
        $skipped = [];

        // Appended to every line's description as "<Type of Service> – <Name>".
        $name_to_serve = $this->nameToServe($job);

        $fee_paragraphs = $job->get('field_job_fees')->referencedEntities();
        foreach ($fee_paragraphs as $index => $fee) {
            $description = 'Service';
            if ($fee->hasField('field_type_of_service') && !$fee->get('field_type_of_service')->isEmpty()) {
                $tid = (int) $fee->get('field_type_of_service')->first()->getValue()['target_id'];
                if ($tid > 0) {
                    $term = $term_storage->load($tid);
                    if ($term) {
                        $description = (string) $term->label();
                    }
                }
            }

            $units = NULL;
            if ($fee->hasField('field_units') && !$fee->get('field_units')->isEmpty()) {
                $units = (float) $fee->get('field_units')->value;
            }
            if ($units === NULL || $units <= 0) {
                $units = 1;
            }

            $unit_amount = NULL;
            if ($fee->hasField('field_agreed_fee') && !$fee->get('field_agreed_fee')->isEmpty()) {
                $unit_amount = (float) $fee->get('field_agreed_fee')->value;
            }
            if (($unit_amount === NULL || $unit_amount <= 0)
                && $fee->hasField('field_standard_fee')
                && !$fee->get('field_standard_fee')->isEmpty()) {
                $unit_amount = (float) $fee->get('field_standard_fee')->value;
            }
            if ($unit_amount === NULL || $unit_amount <= 0) {
                $skipped[] = '#' . ($index + 1) . ' (' . $description . ')';
                continue;
            }

            if ($name_to_serve !== '') {
                $description .= ' ' . self::EN_DASH . ' ' . $name_to_serve;
            }

            $line = [
                'Description' => $description,
                'Quantity' => $units,
                'UnitAmount' => $unit_amount,
                'AccountCode' => '200',
            ];
            if ($discount_rate > 0) {
                $line['DiscountRate'] = $discount_rate;
            }
            $lines[] = $line;
        }

        if (empty($lines)) {
            $msg = 'Job has fee lines but none have a usable amount.';
            if (!empty($skipped)) {
                $msg .= ' Skipped: ' . implode(', ', $skipped) . '.';
            }
            return ['error' => $msg];
        }

        return ['lines' => $lines];
    }

    /**
     * Builds the invoice Reference block for the APS Xero template.
     *
     * Xero exposes a single free-text Reference field, so the whole header
     * block (MATTER + Re: on the left, Your/Our Reference on the right) is
     * packed into it with line breaks; the template renders it via «Reference».
     * Stacked single column (Xero's Reference field is single-line and collapses
     * whitespace, so two-column left/right alignment is not possible):
     *   Our Reference <CLI>12:<JobNo>:SY
     *   Your Reference <field_client_ref>
     *   MATTER: <Job Type> – <Suburb>
     *   <State>
     *   Re: <Side One> -v- <Side Two>
     */
    protected function buildInvoiceReference($job): string {
        $client_ref = $this->fieldValue($job, 'field_client_ref');

        // Our Reference: first 3 letters of the client name (UPPER) + fixed
        // "12:" + Job number (node title) + fixed ":SY".
        $client_name = $this->fieldValue($job, 'field_client_name');
        if ($client_name === '' && $job->hasField('field_client') && !$job->get('field_client')->isEmpty()) {
            $client = $job->get('field_client')->entity;
            if ($client) {
                $client_name = (string) $client->label();
            }
        }
        $client_prefix = mb_strtoupper(mb_substr(trim($client_name), 0, 3));
        $our_ref = $client_prefix . '12:' . trim((string) $job->getTitle()) . ':SY';

        $job_type = mb_strtoupper($this->firstTermLabel($job, 'field_job_types'));
        [$suburb, $state] = $this->partyToServeSuburbState($job);
        $suburb = mb_strtoupper($suburb);
        $state = mb_strtoupper($state);

        $side_one = $this->firstPartyFullName($job, 'field_parties');
        $side_two = $this->firstPartyFullName($job, 'field_party_2');

        $matter_line = $job_type;
        if ($suburb !== '') {
            $matter_line = trim($job_type . ' ' . self::EN_DASH . ' ' . $suburb);
        }

        // Single column, stacked in this order. Xero's Reference is a
        // single-line free-text field that collapses runs of whitespace, so
        // left/right column alignment is impossible; CRLF is used between rows
        // in the hope Xero honours it as a line break in the rendered template.
        $lines = [];
        $lines[] = 'Our Reference ' . $our_ref;
        $lines[] = 'Your Reference ' . $client_ref;
        $lines[] = trim('MATTER: ' . $matter_line);
        if ($state !== '') {
            $lines[] = $state;
        }
        $lines[] = 'Re: ' . $side_one . ' -v- ' . $side_two;

        return implode("\r\n", $lines);
    }

    /**
     * Trimmed scalar value of a simple field, or '' when empty/absent.
     */
    protected function fieldValue($entity, string $field): string {
        if ($entity->hasField($field) && !$entity->get($field)->isEmpty()) {
            return trim((string) $entity->get($field)->value);
        }
        return '';
    }

    /**
     * Label of the first referenced taxonomy term on a field, or ''.
     */
    protected function firstTermLabel($job, string $field): string {
        if (!$job->hasField($field) || $job->get($field)->isEmpty()) {
            return '';
        }
        $terms = $job->get($field)->referencedEntities();
        return $terms ? trim((string) $terms[0]->label()) : '';
    }

    /**
     * Full name of the first party paragraph on a side field. Prefers
     * field_full_name; falls back to first + last name.
     */
    protected function firstPartyFullName($job, string $field): string {
        if (!$job->hasField($field) || $job->get($field)->isEmpty()) {
            return '';
        }
        foreach ($job->get($field)->referencedEntities() as $para) {
            if ($para->hasField('field_full_name') && !$para->get('field_full_name')->isEmpty()) {
                $full = trim((string) $para->get('field_full_name')->value);
                if ($full !== '') {
                    return $full;
                }
            }
            $first = ($para->hasField('field_name') && !$para->get('field_name')->isEmpty())
                ? trim((string) $para->get('field_name')->value) : '';
            $last = ($para->hasField('field_last_name') && !$para->get('field_last_name')->isEmpty())
                ? trim((string) $para->get('field_last_name')->value) : '';
            $composed = trim($first . ' ' . $last);
            if ($composed !== '') {
                return $composed;
            }
        }
        return '';
    }

    /**
     * Suburb + state from the Party-to-Serve service address: the primary
     * addresses paragraph (field_primary_address) else the first one.
     * Mirrors JobFilterController::primaryPostalCode().
     *
     * @return array
     *   [0 => suburb, 1 => state].
     */
    protected function partyToServeSuburbState($job): array {
        if (!$job->hasField('field_service_address') || $job->get('field_service_address')->isEmpty()) {
            return ['', ''];
        }
        $first = NULL;
        $chosen = NULL;
        foreach ($job->get('field_service_address')->referencedEntities() as $para) {
            if (!$para->hasField('field_service_address') || $para->get('field_service_address')->isEmpty()) {
                continue;
            }
            if ($first === NULL) {
                $first = $para;
            }
            if ($para->hasField('field_primary_address') && (bool) $para->get('field_primary_address')->value) {
                $chosen = $para;
                break;
            }
        }
        $chosen = $chosen ?: $first;
        if (!$chosen) {
            return ['', ''];
        }
        $addr = $chosen->get('field_service_address')->first()->getValue();
        return [
            trim((string) ($addr['locality'] ?? '')),
            trim((string) ($addr['administrative_area'] ?? '')),
        ];
    }

    /**
     * The Party-to-Serve "Name to Serve" (field_served_party). No fallback.
     */
    protected function nameToServe($job): string {
        return $this->fieldValue($job, 'field_served_party');
    }
}