<?php

namespace Drupal\associatedps_affidavit_template\Controller;

use Drupal\associatedps_affidavit_template\Service\DocuSignClient;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles the one-time DocuSign JWT consent flow.
 *
 * JWT Grant is server-to-server at runtime, but DocuSign requires the
 * impersonated user to grant consent for the `signature impersonation`
 * scopes once per integration key. After consent, subsequent JWT mints
 * succeed silently — no code-for-token exchange happens here (the access
 * token is minted from the JWT assertion, not the authorization code).
 */
class DocuSignOAuthController extends ControllerBase {

  protected DocuSignClient $docuSignClient;

  public function __construct(DocuSignClient $docusign_client) {
    $this->docuSignClient = $docusign_client;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('associatedps_affidavit_template.docusign_client')
    );
  }

  /**
   * Redirect admin to DocuSign consent page.
   */
  public function authorize(): TrustedRedirectResponse|RedirectResponse {
    try {
      $url = $this->docuSignClient->getAuthorizationUrl();
      return new TrustedRedirectResponse($url);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('DocuSign authorization failed: @msg', ['@msg' => $e->getMessage()]));
      return $this->redirect('associatedps_affidavit_template.docusign_config');
    }
  }

  /**
   * Consent callback — DocuSign redirects here after the user approves.
   *
   * For JWT grant we don't exchange the code; we just acknowledge that
   * consent was granted and try a mint to verify everything is wired up.
   */
  public function callback(Request $request): RedirectResponse {
    $error = $request->query->get('error');
    if ($error) {
      $description = $request->query->get('error_description', '');
      $this->messenger()->addError($this->t('DocuSign consent denied: @err — @desc', [
        '@err' => $error,
        '@desc' => $description,
      ]));
      return $this->redirect('associatedps_affidavit_template.docusign_config');
    }

    $token = $this->docuSignClient->mintJwtAccessToken();
    if (isset($token['error'])) {
      $this->messenger()->addError($this->t('DocuSign consent recorded but JWT mint still failed: @msg', ['@msg' => $token['error']]));
    }
    else {
      $this->messenger()->addStatus($this->t('DocuSign account connected. Account: @aid (@base).', [
        '@aid' => $token['account_id'] ?? '?',
        '@base' => $token['base_uri'] ?? '?',
      ]));
    }
    return $this->redirect('associatedps_affidavit_template.docusign_config');
  }

}
