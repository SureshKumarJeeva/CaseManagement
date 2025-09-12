<?php

namespace Drupal\associatedps_case_status_template\Form;

/**
 * @file
 * Contains Drupal\associatedps_case_status_template\Form\SettingsForm.
 */

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;

/**
 * Class SettingsForm.
 *
 * @package Drupal\associatedps_case_status_template\Form
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'associatedps_case_status_template.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('associatedps_case_status_template.settings');
    $config_email_template = '';
    if (!empty($config->get('email_template')) && $config->get('email_template') !== '_none') {
      $config_email_template = $config->get('email_template');
    }
    $db = Database::getConnection();
    $query = db_select('node_field_data', 'n');
    $query->fields('n', ['nid', 'title']);
    $query->leftjoin('node__field_category', 'fc', 'fc.entity_id = n.nid');
    $query->fields('fc', ['field_category_value']);
    $query->condition('fc.field_category_value', 'Email', '=');
    $result = $query->execute();
    $email_template['_none'] = '- None -';
    foreach ($result as $email_value) {
      $email_template[$email_value->nid] = $email_value->title;
    }
    $form['email_template'] = [
      '#type' => 'select',
      '#title' => $this->t('Email Template'),
      '#options' => $email_template,
      '#default_value' => $config_email_template,
      '#description' => 'Select Email Template for send auto email on change of Case Status.',
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $this->config('associatedps_case_status_template.settings')
      ->set('email_template', $form_state->getValue('email_template'))
      ->save();
  }

}
