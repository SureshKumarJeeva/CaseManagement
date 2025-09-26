<?php

namespace Drupal\simple_multistep\Plugin\field_group\FieldGroupFormatter;

use Drupal\Component\Utility\Html;
use Drupal\field_group\FieldGroupFormatterBase;

/**
 * Plugin class implementation of the 'form_step' formatter.
 *
 * @FieldGroupFormatter(
 *   id = "form_step",
 *   label = @Translation("Form step"),
 *   description = @Translation("Provide simple form step"),
 *   supported_contexts = {
 *     "form",
 *   }
 * )
 */
class FormStep extends FieldGroupFormatterBase {

  /**
   * {@inheritdoc}
   */
  public function process(&$element, $processed_object) {
    $element += [
      '#type' => 'container',
      '#attributes' => [],
    ];

    if ($this->getSetting('id')) {
      $element['#id'] = Html::getUniqueId($this->getSetting('id'));
    }

    $classes = $this->getClasses();
    if (!empty($classes)) {
      $element['#attributes'] += ['class' => $classes];
    }

    if ($this->getSetting('required_fields')) {
      $element['#attached']['library'][] = 'field_group/formatter.fieldset';
      $element['#attached']['library'][] = 'field_group/core';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function preRender(&$element, $rendering_object) {
    parent::preRender($element, $rendering_object);
    $this->process($element, $rendering_object);
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm() {
    $form = parent::settingsForm();

    $form['label']['#title'] = $this->t('Step title');

    $form['show_step_title'] = [
      '#title' => $this->t('Show step title'),
      '#type' => 'checkbox',
      '#default_value' => $this->getSetting('show_step_title'),
      '#description' => $this->t('Show step title'),
      '#weight' => 1,
    ];

    $form['back_button_show'] = [
      '#title' => $this->t('Show back button'),
      '#type' => 'checkbox',
      '#default_value' => $this->getSetting('back_button_show'),
      '#description' => $this->t('Back button for form. Don`t show on first step'),
      '#weight' => 2,
    ];

    $form['back_button_text'] = [
      '#title' => $this->t('Text for back button'),
      '#type' => 'textfield',
      '#default_value' => $this->getSetting('back_button_text'),
      '#description' => $this->t('Text which will be show on back button'),
      '#weight' => $form['back_button_show']['#weight'] + 0.1,
    ];

    $form['next_button_text'] = [
      '#title' => $this->t('Text for next button'),
      '#type' => 'textfield',
      '#default_value' => $this->getSetting('next_button_text'),
      '#description' => $this->t('Text which will be show on next button'),
    ];

    $form['step_description'] = [
      '#title' => $this->t('Text for step description'),
      '#type' => 'textarea',
      '#default_value' => $this->getSetting('step_description'),
      '#description' => $this->t('Text which will be show on step desription'),
    ];

    $form['step_help'] = [
      '#title' => $this->t('Text for step help'),
      '#type' => 'textarea',
      '#default_value' => $this->getSetting('step_help'),
      '#description' => $this->t('Text which will be show on step help'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $summary[] = $this->t('Back button: @element',
      ['@element' => $this->getSetting('back_button_show') ? $this->t('Show') : $this->t('Hide')]
    );

    $summary[] = $this->t('Show title: @element',
      ['@element' => $this->getSetting('show_step_title') ? $this->t('Show') : $this->t('Hide')]
    );

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultContextSettings($context) {
    $defaults = [
      'back_button_show' => FALSE,
      'back_button_text' => t('Back'),
      'next_button_text' => t('Next'),
      'step_description' => '',
      'step_help' => '',
      'show_step_title' => TRUE,
    ] + parent::defaultSettings($context);

    if ($context === 'form') {
      $defaults['required_fields'] = TRUE;
    }

    return $defaults;
  }

}
