<?php

/**
 * @file
 * Functions to support Seven theme settings.
 */

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_FORM_ID_alter() for system_theme_settings.
 */
function seven_form_system_theme_settings_alter(&$form, FormStateInterface $form_state) {
  $form['seven_settings'] = [
    '#type' => 'fieldset',
    '#title' => t('Seven utilities'),
  ];

  $form['seven_settings']['enable_block_contextual_links'] = [
    '#type' => 'checkbox',
    '#title' => t('Enable contextual links for blocks'),
    '#default_value' => theme_get_setting('enable_block_contextual_links'),
  ];
}
