<?php

namespace Drupal\associatedps_affidavit_template\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\associatedps_affidavit_template\Service\XeroClient;
use Drupal\Core\Url;

class XeroAccountConfigForm extends ConfigFormBase {

   /**
   * Xero service.
   *
   * @var \Drupal\associatedps_affidavit_template\Service\XeroClient
   */
  protected XeroClient $xeroClient;

  /**
    * Constructor.
    */
  public function __construct(XeroClient $xero_client) {
    $this->xeroClient = $xero_client;
  }

  /**
   * Override the create method to inject the XeroClient service.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('associatedps_affidavit_template.xero_client')
    );
  }
  
  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['associatedps_affidavit_template.xero'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'associatedps_affidavit_template_xero_account_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('associatedps_affidavit_template.xero');

    // Get ENV defaults via service.
    $env_client_id = $this->xeroClient->getClientId();
    $env_client_secret = $this->xeroClient->getClientSecret();

    $form['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Xero Client ID'),
      '#default_value' => $env_client_id,
      '#required' => TRUE,
    ];

    $form['client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Xero Client Secret'),
      '#default_value' => $env_client_secret,
      '#required' => TRUE,
    ];

    // OAuth start button
    $form['authorize'] = [
      '#type' => 'link',
      '#title' => $this->t('Authorize Xero Account'),
      '#url' => Url::fromRoute('associatedps_affidavit_template.xero_oauth_start'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // $this->configFactory->getEditable('associatedps_affidavit_template.xero')
    //   ->set('client_id', $form_state->getValue('client_id'))
    //   ->set('client_secret', $form_state->getValue('client_secret'))
    //   ->save();
      $this->xeroClient->setClientId($form_state->getValue('client_id'));
      $this->xeroClient->setClientSecret($form_state->getValue('client_secret'));

    parent::submitForm($form, $form_state);
  }
} 