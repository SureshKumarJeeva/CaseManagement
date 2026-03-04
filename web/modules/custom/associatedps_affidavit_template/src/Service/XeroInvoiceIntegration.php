<?php

namespace Drupal\associatedps_affidavit_template\Service;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Database\Connection;
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

     /**
     * Constructor.
     */
    public function __construct(XeroClient $xero_service, Connection $database) {
        $this->xeroService = $xero_service;
        $this->database = $database;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container): static {
        return new static(
            $container->get('associatedps_affidavit_template.xero_client')
        );
    }

    /**
     * Creates an invoice in Xero account.
     */
    public function createInvoice($jobId): array {
        \Drupal::logger('associatedps_affidavit_template_Xero')->info('Creating invoice for Job ID: @jobId', ['@jobId' => $jobId]);
        try{
            // Call the XeroClient service to create an invoice.
            $xml_contacts = $this->xeroService->getContacts();
            if(isset($xml_contacts->error)){
                return ['error' => 'Unable to fetch contacts from Xero for invoice generation: ' . (string)$xml_contacts->error];
            }
            \Drupal::logger('associatedps_affidavit_template_Xero')->info('Fetched contacts from Xero: @contacts', ['@contacts' => $xml_contacts->asXML()]);
            \Drupal::logger('associatedps_affidavit_template_Xero')->info('Fetched contactid from Xero: @contactid', ['@contactid' => (string)$xml_contacts->Contacts->Contact[0]->ContactID]);
            $now = new \DateTime();
            $formattedCurrentDate = $now->format('Y-m-d\TH:i:s');
            $formattedDueDate = $now->modify('+30 days')->format('Y-m-d\TH:i:s');
            $invoice_payload = [
                'Invoice' => [
                    'Type' => 'ACCREC',
                    'Contact' => [
                        'ContactID' => (string)$xml_contacts->Contacts->Contact[0]->ContactID,
                    ],
                    'Date' => '\/Date(' . strtotime(date('Y-m-d')) * 1000 . '+0000)\/',
                    "DateString" => $formattedCurrentDate,
                    'DueDate' => '\/Date(' . strtotime(date('Y-m-d', strtotime('+30 days'))) * 1000 . '+0000)\/',
                    "DueDateString" => $formattedDueDate,
                    "LineAmountTypes" => "Exclusive",
                    'LineItems' => [
                                'Description' => 'Service for Job ID: ' . $jobId,
                                'Quantity' => 1,
                                'UnitAmount' => 100.00,
                                'AccountCode' => '200',
                                "DiscountRate" => "20"
                    ],
                    'Status' => 'AUTHORISED',
                ],
            ];
            $json_invoiceID = $this->xeroService->generatePaidInvoice(json_encode($invoice_payload));
            if(isset($json_invoiceID['error'])){
                return ['error' => 'Unable to create invoice in Xero: ' . (string)$json_invoiceID['error']];
            }
            return ['message' => $json_invoiceID['message']];
        }catch (\Exception $e) {
            \Drupal::logger('associatedps_affidavit_template_Xero')->error('Xero createInvoice error: @msg', ['@msg' => $e->getMessage()]);
            return ['error' => 'Unable to create invoice: ' . $e->getMessage()];
        }
    }
}