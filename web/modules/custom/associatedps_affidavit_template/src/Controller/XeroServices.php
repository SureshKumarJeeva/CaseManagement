<?php


namespace Drupal\associatedps_affidavit_template\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\associatedps_affidavit_template\Service\XeroClient;
use Drupal\associatedps_affidavit_template\Service\XeroInvoiceIntegration;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;

/** 
 * Acts as Xero services controller
 * 
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
     * Generates an invoice in Xero.
     */
    public function generateInvoice(Request $request): AjaxResponse {
        $response = new AjaxResponse();
        try {
            $jobId = $request->get('jobId');
            $this->messenger()->addStatus($this->t('Attempting to generate invoice for job ID: @jobId', [
                '@jobId' => $jobId,
            ]));
            $response_msg = $this->xeroInvoiceIntegration->createInvoice($jobId);
            if(isset($response_msg['error'])){
                $response->addCommand(new OpenModalDialogCommand(
                    'Invoice',
                    ['#markup' => $response_msg['error']]
                ));
            }else{
                $response->addCommand(new OpenModalDialogCommand(
                    'Invoice',
                    ['#markup' => 'Invoice generated for Job ID: ' . $response_msg['message']]
                ));
            }
        }
        catch (\Exception $e) {
            $response->addCommand(new OpenModalDialogCommand(
                'Invoice',
                ['#markup' => 'Failed to generate invoice for Job ID: ' . $jobId.$e->getMessage()]
            ));
        }
        return $response;

    }

   
}