<?php

namespace Drupal\associatedps_affidavit_template\Form;

use Drupal\associatedps_affidavit_template\Service\DocuSignClient;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form for the DocuSign integration.
 *
 * All credentials live in .env (env-only, mirroring PandaDocConfigForm).
 * This form just shows status, lets the admin trigger one-time consent,
 * and runs a connection test.
 */
class DocuSignConfigForm extends ConfigFormBase {

  protected DocuSignClient $docuSignClient;

  public function __construct(DocuSignClient $docusign_client) {
    $this->docuSignClient = $docusign_client;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('associatedps_affidavit_template.docusign_client')
    );
  }

  protected function getEditableConfigNames() {
    return ['associatedps_affidavit_template.docusign'];
  }

  public function getFormId() {
    return 'associatedps_affidavit_template_docusign_config_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $diag = $this->docuSignClient->getDiagnostics();

    $form['status'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Configuration status'),
    ];
    $form['status']['integration_key'] = [
      '#markup' => '<p><strong>Integration Key:</strong> ' . ($diag['integration_key_set'] ? '✅ set' : '❌ missing — set DOCUSIGN_INTEGRATION_KEY in .env') . '</p>',
    ];
    $form['status']['user_id'] = [
      '#markup' => '<p><strong>API User ID:</strong> ' . ($diag['user_id_set'] ? '✅ set' : '❌ missing — set DOCUSIGN_USER_ID in .env') . '</p>',
    ];
    $form['status']['private_key'] = [
      '#markup' => '<p><strong>RSA Private Key:</strong> ' . ($diag['private_key_readable'] ? '✅ readable' : '❌ missing/unreadable — set DOCUSIGN_PRIVATE_KEY_PATH or DOCUSIGN_PRIVATE_KEY in .env') . '</p>',
    ];
    $form['status']['oauth_base'] = [
      '#markup' => '<p><strong>OAuth base:</strong> ' . htmlspecialchars($diag['oauth_base']) . '</p>',
    ];
    $form['status']['redirect_uri'] = [
      '#markup' => '<p><strong>Redirect URI (must match DocuSign Apps &amp; Keys):</strong> <code>' . htmlspecialchars($diag['redirect_uri']) . '</code></p>',
    ];
    $form['status']['scopes'] = [
      '#markup' => '<p><strong>JWT scopes:</strong> ' . htmlspecialchars($diag['scopes']) . '</p>',
    ];
    $form['status']['owner'] = [
      '#markup' => '<p><strong>Placeholder recipient (never emailed — envelopes stay as drafts):</strong> ' . htmlspecialchars($diag['owner_email']) . '</p>',
    ];

    $form['consent'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('One-time consent'),
      '#description' => $this->t('JWT Grant requires the impersonated user to grant consent once per integration key. Click below to begin (you will be redirected to DocuSign).'),
    ];
    $form['consent']['authorize_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Authorize (one-time consent)'),
      '#url' => Url::fromRoute('associatedps_affidavit_template.docusign_oauth_start'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $webhookUrl = \Drupal::request()->getSchemeAndHttpHost() . '/docusign/connect/webhook';
    $form['webhook'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('DocuSign Connect (webhook)'),
    ];
    $form['webhook']['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook URL'),
      '#description' => $this->t('Auto-registered with DocuSign Connect on first template upload. HMAC-signed using DOCUSIGN_CONNECT_HMAC_KEY.'),
      '#default_value' => $webhookUrl,
      '#disabled' => TRUE,
    ];

    $form['actions_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['style' => 'margin-top: 1em;'],
    ];
    $form['actions_wrapper']['test_connection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test Connection'),
      '#submit' => ['::testConnection'],
      '#attributes' => ['class' => ['button']],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Submit handler: try to mint a token and report the result.
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    $token = $this->docuSignClient->getValidAccessToken();
    if (isset($token['error'])) {
      $this->messenger()->addError($this->t('Connection failed: @err', ['@err' => $token['error']]));
      return;
    }
    $this->messenger()->addStatus($this->t('Connected to account @aid at @base. Token expires at @exp.', [
      '@aid' => $token['account_id'] ?? '?',
      '@base' => $token['base_uri'] ?? '?',
      '@exp' => isset($token['expires_at']) ? date('Y-m-d H:i:s', (int) $token['expires_at']) : '?',
    ]));
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->messenger()->addStatus($this->t('DocuSign settings are managed via .env file.'));
    parent::submitForm($form, $form_state);
  }

}
