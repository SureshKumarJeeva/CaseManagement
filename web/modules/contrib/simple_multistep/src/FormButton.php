<?php

namespace Drupal\simple_multistep;

use Drupal\Core\Form\FormStateInterface;

/**
 * Class FormButton.
 *
 * @package Drupal\simple_multistep
 */
class FormButton extends FormStep {

  /**
   * Constructor.
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
   * Show back button.
   *
   * @param array $form
   *   Reference to form array.
   */
  private function showBackButton(array &$form) {
    $step_format_settings = $this->stepSettings->format_settings;

    if ($this->currentStep != 0 && !empty($step_format_settings['back_button_show'])) {
      // Add back button and remove validation.
      $form['actions']['back_button'] = [
        '#type' => 'button',
        '#value' => $step_format_settings['back_button_text'],
        '#validate' => ['simple_multistep_register_back'],
        '#submit' => [],
        '#limit_validation_errors' => [],
        '#weight' => 0,
      ];
    }
  }

  /**
   * Show next button.
   *
   * @param array $form
   *   Reference to form array.
   */
  private function showNextButton(array &$form) {
    $step_format_settings = $this->stepSettings->format_settings;

    if (count($this->steps) - 1 != $this->currentStep) {
      $form['actions']['next'] = [
        '#type' => 'button',
        '#value' => $step_format_settings['next_button_text'],
        '#validate' => ['simple_multistep_register_next_step'],
        '#submit' => [],
        '#weight' => 0.1,
      ];
      $form['actions']['submit']['#access'] = FALSE;
    }
    // On last step hide next button and show save button.
    else {
      $form['actions']['submit']['#access'] = TRUE;
      array_unshift($form['#validate'], 'simple_multistep_multistep_validate');
    }
  }

  /**
   * Show save button.
   *
   * @param array $form
   *   Reference to form array.
   */
  private function showSaveButton(array &$form) {

    if (count($this->steps) - 1 != $this->currentStep) {
      if ($this->currentStep == 0) {
        $form['actions']['submit']['#access'] = TRUE;
      }
      else {
        $form['actions']['submit']['#access'] = TRUE;
        $form['actions']['submit']['#submit'][] = 'simple_multistep_after_save_next_step';
      }
    }
    // On last step hide next button and show save button.
    else {
      $form['actions']['submit']['#access'] = TRUE;
    }
  }

  /**
   * Render form button.
   *
   * @param array $form
   *   Form array.
   */
  public function render(array &$form) {
    $this->showBackButton($form);
    $this->showNextButton($form);
    $this->showSaveButton($form);
  }

}
