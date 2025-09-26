<?php

namespace Drupal\simple_multistep;

use Drupal\Core\Form\FormStateInterface;

/**
 * The FormText class.
 *
 * @package Drupal\simple_multistep
 */
class FormText extends FormStep {

  /**
   * Constructor for FormText.
   *
   * @param array $form
   *   Form settings.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state object.
   * @param int $current_step
   *   Current step.
   */
  public function __construct(array $form, FormStateInterface $form_state, $current_step) {
    parent::__construct($form, $form_state);

    $this->currentStep = $current_step;
    $this->fetchStepSettings();
  }

  /**
   * Show step description.
   *
   * @param array $form
   *   Reference to form array.
   */
  private function showStepDescription(array &$form) {
    $step_format_settings = $this->stepSettings->format_settings;

    if (isset($step_format_settings['step_description']) && $step_format_settings['step_description'] != '') {
      $form['step_description'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $step_format_settings['step_description'],
        '#attributes' => [
          'class' => ['step-description'],
        ],
        '#weight' => -1,
      ];
    }
  }

  /**
   * Show step help.
   *
   * @param array $form
   *   Reference to form array.
   */
  private function showStepHelp(array &$form) {
    $step_format_settings = $this->stepSettings->format_settings;

    if (isset($step_format_settings['step_help']) && $step_format_settings['step_help'] != '') {
      $form['step_help'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $step_format_settings['step_help'],
        '#attributes' => [
          'class' => ['step-help'],
        ],
        '#weight' => -1,
      ];
    }
  }

  /**
   * Render form button.
   *
   * @param array $form
   *   Form array.
   */
  public function render(array &$form) {
    $this->showStepDescription($form);
    $this->showStepHelp($form);
  }

}
