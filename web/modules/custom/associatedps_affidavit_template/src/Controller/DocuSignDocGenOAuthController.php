<?php

namespace Drupal\associatedps_affidavit_template\Controller;

use Drupal\associatedps_affidavit_template\Service\DocuSignDocGenClient;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * One-time JWT consent flow for the Document Generation sandbox account.
 *
 * Same mechanics as DocuSignOAuthController — consent is per integration key,
 * and the access token is minted from the JWT assertion rather than exchanged
 * for the authorization code — but pointed at the other account, with its own
 * redirect URI and its own token row. Two accounts means two consents.
 */
class DocuSignDocGenOAuthController extends ControllerBase {

  protected DocuSignDocGenClient $client;

  public function __construct(DocuSignDocGenClient $client) {
    $this->client = $client;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('associatedps_affidavit_template.docusign_docgen_client')
    );
  }

  /**
   * Send the admin to DocuSign's consent screen for the docgen account.
   */
  public function authorize(): TrustedRedirectResponse|RedirectResponse {
    if (!$this->client->isConfigured()) {
      $this->messenger()->addError($this->client->getConfigurationError());
      return $this->redirect('associatedps_affidavit_template.docusign_docgen_config');
    }

    try {
      return new TrustedRedirectResponse($this->client->getAuthorizationUrl());
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Doc Gen authorization failed: @msg', ['@msg' => $e->getMessage()]));
      return $this->redirect('associatedps_affidavit_template.docusign_docgen_config');
    }
  }

  /**
   * Consent callback — verify the grant by minting a token straight away.
   */
  public function callback(Request $request): RedirectResponse {
    $error = $request->query->get('error');
    if ($error) {
      $this->messenger()->addError($this->t('Doc Gen consent denied: @err — @desc', [
        '@err' => $error,
        '@desc' => $request->query->get('error_description', ''),
      ]));
      return $this->redirect('associatedps_affidavit_template.docusign_docgen_config');
    }

    $token = $this->client->mintJwtAccessToken();
    if (isset($token['error'])) {
      $this->messenger()->addError($this->t('Doc Gen consent recorded but the JWT mint still failed: @msg', ['@msg' => $token['error']]));
    }
    else {
      $this->messenger()->addStatus($this->t('Doc Gen account connected. Account: @aid (@base).', [
        '@aid' => $token['account_id'] ?? '?',
        '@base' => $token['base_uri'] ?? '?',
      ]));
    }
    return $this->redirect('associatedps_affidavit_template.docusign_docgen_config');
  }

}
