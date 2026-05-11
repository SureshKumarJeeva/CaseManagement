<?php

namespace Drupal\associatedps_affidavit_template\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\associatedps_affidavit_template\Service\PandaDocClient;

class PandaDocConfigForm extends ConfigFormBase {

  protected PandaDocClient $pandaDocClient;

  public function __construct(PandaDocClient $pandadoc_client) {
    $this->pandaDocClient = $pandadoc_client;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('associatedps_affidavit_template.pandadoc_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['associatedps_affidavit_template.pandadoc'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'associatedps_affidavit_template_pandadoc_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('PandaDoc API Key'),
      '#description' => $this->t('Set in .env as PANDADOC_API_KEY. Current status: @status', [
        '@status' => !empty(getenv('PANDADOC_API_KEY')) ? 'Configured' : 'Not set',
      ]),
      '#default_value' => '',
      '#attributes' => [
        'placeholder' => !empty(getenv('PANDADOC_API_KEY')) ? '••••••••••••••••' : 'Enter API Key',
      ],
    ];

    $form['sandbox_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sandbox Mode'),
      '#description' => $this->t('When enabled, uses PandaDoc sandbox environment.'),
      '#default_value' => filter_var(getenv('PANDADOC_SANDBOX'), FILTER_VALIDATE_BOOLEAN),
      '#disabled' => TRUE,
    ];

    $webhookUrl = \Drupal::request()->getSchemeAndHttpHost() . '/pandadoc/webhook';
    $form['webhook_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook URL'),
      '#description' => $this->t('This URL is auto-registered with PandaDoc on first Library upload. Copy this if you need to register manually.'),
      '#default_value' => $webhookUrl,
      '#disabled' => TRUE,
    ];

    $form['test_connection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test Connection'),
      '#submit' => ['::testConnection'],
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Test connection by calling listTemplates().
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    $result = $this->pandaDocClient->listTemplates('', 1, 1);

    if (isset($result['error'])) {
      $this->messenger()->addError($this->t('Connection failed: @error', ['@error' => $result['error']]));
    }
    else {
      $count = $result['count'] ?? count($result['results'] ?? []);
      $this->messenger()->addStatus($this->t('Connection successful. Found @count template(s) in your PandaDoc account.', ['@count' => $count]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->messenger()->addStatus($this->t('PandaDoc API settings are managed via .env file. Update PANDADOC_API_KEY in your .env to change the API key.'));
    parent::submitForm($form, $form_state);
  }

}
