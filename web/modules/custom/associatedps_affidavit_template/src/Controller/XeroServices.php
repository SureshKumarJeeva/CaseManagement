<?php


namespace Drupal\associatedps_affidavit_template\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\associatedps_affidavit_template\Service\XeroInvoiceIntegration;

/**
 * Acts as Xero services controller.
 */
class XeroServices extends ControllerBase {

    /**
     * Xero services integration.
     *
     * @var \Drupal\associatedps_affidavit_template\Service\XeroInvoiceIntegration
     */
    protected XeroInvoiceIntegration $xeroInvoiceIntegration;

    /**
     * Constructor.
     */
    public function __construct(XeroInvoiceIntegration $xero_invoice_integration) {
        $this->xeroInvoiceIntegration = $xero_invoice_integration;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container): static {
        return new static(
            $container->get('associatedps_affidavit_template.xero_invoice_integration')
        );
    }

    /**
     * Generates an invoice in Xero and redirects to the invoice edit page.
     */
    public function generateInvoice(Request $request, $jobId): Response {
        try {
            $response_msg = $this->xeroInvoiceIntegration->createInvoice($jobId);

            if (isset($response_msg['error'])) {
                $this->messenger()->addError($response_msg['error']);
                return $this->redirect('entity.node.edit_form', ['node' => $jobId]);
            }

            $invoiceId = $response_msg['invoiceId'];
            $xeroUrl = 'https://go.xero.com/AccountsReceivable/Edit.aspx?InvoiceID=' . $invoiceId;

            return new TrustedRedirectResponse($xeroUrl);
        }
        catch (\Exception $e) {
            $this->messenger()->addError('Failed to generate invoice: ' . $e->getMessage());
            return $this->redirect('entity.node.edit_form', ['node' => $jobId]);
        }
    }

}
