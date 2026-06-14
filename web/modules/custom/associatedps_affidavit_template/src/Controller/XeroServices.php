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
     * Generates a Xero DRAFT invoice, pushes its number back to the Job form,
     * then forwards this (new) tab to the Xero invoice editor.
     *
     * Generate Invoice opens in a NEW tab while the Job edit form stays open in
     * the opener tab. Xero returns the InvoiceNumber on draft creation, so we
     * render a small bridge page that writes that number into the opener's
     * read-only Invoice Number field, then redirects this tab to Xero. We do NOT
     * save the Job — the user decides whether to keep the invoice (they may
     * delete the draft in Xero), then Saves the Job themselves to record it.
     */
    public function generateInvoice(Request $request, $jobId): Response {
        try {
            $response_msg = $this->xeroInvoiceIntegration->createInvoice($jobId);

            if (isset($response_msg['error'])) {
                $this->messenger()->addError($response_msg['error']);
                return $this->redirect('entity.node.edit_form', ['node' => $jobId]);
            }

            $invoiceId = $response_msg['invoiceId'];
            $invoiceNumber = (string) ($response_msg['invoiceNumber'] ?? '');
            $xeroUrl = 'https://go.xero.com/AccountsReceivable/Edit.aspx?InvoiceID=' . $invoiceId;

            $num_js = json_encode($invoiceNumber);
            $url_js = json_encode($xeroUrl);
            $num_html = htmlspecialchars($invoiceNumber, ENT_QUOTES, 'UTF-8');

            // Bridge page: inject the invoice number into the opener (Job form),
            // then forward to the Xero editor. The Generate Invoice link uses
            // rel="opener" so window.opener is available (same origin).
            $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Generating invoice…</title></head>
<body style="font-family:sans-serif;padding:2rem;">
<p>Invoice <strong>{$num_html}</strong> created. Opening Xero…</p>
<script>
(function () {
  var num = {$num_js};
  var xeroUrl = {$url_js};
  try {
    if (num && window.opener && !window.opener.closed) {
      var doc = window.opener.document;
      var el = doc.getElementById('edit-field-invoice-number-0-value')
        || doc.querySelector('[name="field_invoice_number[0][value]"]');
      if (el) {
        el.value = num;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
  } catch (e) {}
  window.location.replace(xeroUrl);
})();
</script>
</body>
</html>
HTML;

            return new Response($html);
        }
        catch (\Exception $e) {
            $this->messenger()->addError('Failed to generate invoice: ' . $e->getMessage());
            return $this->redirect('entity.node.edit_form', ['node' => $jobId]);
        }
    }

}
