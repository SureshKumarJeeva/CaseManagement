<?php

namespace Drupal\associatedps_affidavit_template\Service;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\associatedps_affidavit_template\Service\XeroClient;

class XeroInvoiceIntegration {

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
            $invoice_payload = [
                'Invoices' => [
                  [
                    'Type' => 'ACCREC',
                    'Contact' => [
                        'ContactID' => $contact_id,
                    ],
                    'Date' => $currentDate,
                    'DueDate' => $dueDate,
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
}