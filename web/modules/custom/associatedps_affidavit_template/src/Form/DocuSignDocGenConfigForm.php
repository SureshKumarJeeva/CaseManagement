<?php

namespace Drupal\associatedps_affidavit_template\Form;

use Drupal\associatedps_affidavit_template\Service\DocuSignDocGenClient;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin status page for the DocuSign Document Generation sandbox account.
 *
 * Deliberately separate from DocuSignConfigForm rather than a mode toggle on
 * it: the two pages report on two different DocuSign accounts, each with its
 * own consent state and its own access token, and conflating them is how an
 * admin ends up granting consent to the wrong one. Credentials live in .env
 * here too — this page only reports status and triggers one-time consent.
 */
class DocuSignDocGenConfigForm extends ConfigFormBase {

  protected DocuSignDocGenClient $client;

  public function __construct(DocuSignDocGenClient $client) {
    $this->client = $client;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('associatedps_affidavit_template.docusign_docgen_client')
    );
  }

  protected function getEditableConfigNames() {
    return ['associatedps_affidavit_template.docusign_docgen'];
  }

  public function getFormId() {
    return 'associatedps_affidavit_template_docusign_docgen_config_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $diag = $this->client->getDiagnostics();

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Document Generation runs against its own DocuSign sandbox account, kept separate from the live account used by the Template Upload flow. Every value below comes from a <code>DOCUSIGN_DOCGEN_*</code> variable in <code>.env</code>.') . '</p>',
    ];

    $form['status'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Configuration status'),
    ];
    $form['status']['integration_key'] = [
      '#markup' => '<p><strong>Integration Key:</strong> ' . ($diag['integration_key_set'] ? '✅ set' : '❌ missing — set DOCUSIGN_DOCGEN_INTEGRATION_KEY in .env') . '</p>',
    ];
    $form['status']['user_id'] = [
      '#markup' => '<p><strong>API User ID:</strong> ' . ($diag['user_id_set'] ? '✅ set' : '❌ missing — set DOCUSIGN_DOCGEN_USER_ID in .env') . '</p>',
    ];
    $form['status']['private_key'] = [
      '#markup' => '<p><strong>RSA Private Key:</strong> ' . ($diag['private_key_readable'] ? '✅ readable' : '❌ missing/unreadable — set DOCUSIGN_DOCGEN_PRIVATE_KEY_PATH or DOCUSIGN_DOCGEN_PRIVATE_KEY in .env') . '</p>',
    ];
    $form['status']['oauth_base'] = [
      '#markup' => '<p><strong>OAuth base:</strong> ' . htmlspecialchars($diag['oauth_base']) . '</p>',
    ];
    $form['status']['redirect_uri'] = [
      '#markup' => '<p><strong>Redirect URI (must match this app in DocuSign Apps &amp; Keys):</strong> <code>' . htmlspecialchars($diag['redirect_uri']) . '</code></p>',
    ];
    $form['status']['scopes'] = [
      '#markup' => '<p><strong>JWT scopes:</strong> ' . htmlspecialchars($diag['scopes']) . '</p>',
    ];
    $form['status']['owner'] = [
      '#markup' => '<p><strong>Template placeholder recipient:</strong> ' . htmlspecialchars($diag['owner_email']) . '</p>',
    ];

    $form['consent'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('One-time consent'),
      '#description' => $this->t('JWT Grant requires the impersonated user to grant consent once per integration key. This is a <em>separate</em> grant from the main DocuSign account — consenting there does not consent here.'),
    ];
    $form['consent']['authorize_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Authorize Doc Gen account (one-time consent)'),
      '#url' => Url::fromRoute('associatedps_affidavit_template.docusign_docgen_oauth_start'),
      '#attributes' => ['class' => ['button', 'button--primary']],
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
   * Mint a token against the docgen account and report what came back.
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    if (!$this->client->isConfigured()) {
      $this->messenger()->addError($this->client->getConfigurationError());
      return;
    }

    $token = $this->client->getValidAccessToken();
    if (isset($token['error'])) {
      $this->messenger()->addError($this->t('Connection failed: @err', ['@err' => $token['error']]));
      return;
    }
    $this->messenger()->addStatus($this->t('Connected to Doc Gen account @aid at @base. Token expires at @exp.', [
      '@aid' => $token['account_id'] ?? '?',
      '@base' => $token['base_uri'] ?? '?',
      '@exp' => isset($token['expires_at']) ? date('Y-m-d H:i:s', (int) $token['expires_at']) : '?',
    ]));
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->messenger()->addStatus($this->t('Doc Gen settings are managed via the .env file.'));
    parent::submitForm($form, $form_state);
  }

}
