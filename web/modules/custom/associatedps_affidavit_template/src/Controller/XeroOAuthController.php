<?php

namespace Drupal\associatedps_affidavit_template\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\associatedps_affidavit_template\Service\XeroClient;

/**
 * Handles Xero OAuth authorization flow.
 */
class XeroOAuthController extends ControllerBase {

  /**
   * Xero service.
   *
   * @var \Drupal\associatedps_affidavit_template\Service\XeroClient
   */
  protected XeroClient $xeroService;

  /**
   * Constructor.
   */
  public function __construct(XeroClient $xero_service) {
    $this->xeroService = $xero_service;
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
   * Redirects admin to Xero authorization page.
   */
  public function authorize(): TrustedRedirectResponse {
    try {
      $auth_url = $this->xeroService->getAuthorizationUrl();
      return new TrustedRedirectResponse($auth_url);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Xero authorization failed: @msg', [
        '@msg' => $e->getMessage(),
      ]));

      return $this->redirect('associatedps_affidavit_template.xero_account_config');
    }
  }

  /**
   * Handles Xero OAuth callback.
   */
  public function callback(Request $request): RedirectResponse {
    $code = $request->query->get('code');

    if (!$code) {
      $this->messenger()->addError($this->t('Authorization code missing from Xero response.'));
      return $this->redirect('associatedps_affidavit_template.xero_account_config');
    }

    try {
      \Drupal::logger('associatedps_affidavit_template')->notice('Xero callback received with code: @code', ['@code' => $code]);
      $token_received = $this->xeroService->fetchAccessToken($code);
      $this->messenger()->addStatus($this->t('Xero account connected successfully.'));
      // return new TrustedRedirectResponse($token_url);

      // Exchange code for tokens & store them.
      // $this->xeroService->handleCallback($code, $state);

    }
    catch (\Exception $e) {
      \Drupal::logger('associatedps_affidavit_template')->error('Xero callback error: @msg', ['@msg' => $e->getMessage()]);
      throw $e;
    }

    return $this->redirect('associatedps_affidavit_template.xero_account_config');
  }

  /*
  * Handle token response
  */
  public function handleTokens(Request $request): RedirectResponse {
    //this funcion is not being referenced only at routing and none other places. Remove this later after verifying that xero acconunt is functioning.
    die;
    $code = $request->query->get('code');
    $state = $request->query->get('state');

    if (!$code) {
      $this->messenger()->addError($this->t('Authorization code missing from Xero response.'));
      return $this->redirect('associatedps_affidavit_template.xero_account_config');
    }

    try {
      // Exchange code for tokens & store them.
      $this->xeroService->handleCallback($code, $state);
      $this->messenger()->addStatus($this->t('Xero account connected successfully.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Xero connection failed: @msg', [
        '@msg' => $e->getMessage(),
      ]));
    }

    return $this->redirect('associatedps_affidavit_template.xero_account_config');
  }

}