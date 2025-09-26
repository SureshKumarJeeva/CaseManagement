<?php

namespace Drupal\simple_multistep;

/**
 * Provides controller routines for Multistep forms.
 *
 * @package Drupal\simple_multistep
 */
class MultistepController extends FormStep implements MultistepControllerInterface {

  /**
   * Steps indicator.
   *
   * @var \Drupal\simple_multistep\StepIndicator
   */
  public StepIndicator $stepIndicator;

  /**
   * Form button.
   *
   * @var \Drupal\simple_multistep\FormButton
   */
  public FormButton $formButton;

  /**
   * Form text.
   *
   * @var \Drupal\simple_multistep\FormText
   */
  public FormText $formText;

  /**
   * {@inheritdoc}
   */
  public function rebuildForm(array &$form): void {
    // Add step indicator.
    $this->stepIndicator = new StepIndicator($form, $this->formState, $this->currentStep);
    $this->stepIndicator->render($form);

    // Add additional button for form.
    $this->formButton = new FormButton($form, $this->formState, $this->currentStep);
    $this->formButton->render($form);

    // Add form text.
    $this->formText = new FormText($form, $this->formState, $this->currentStep);
    $this->formText->render($form);

    foreach ($this->steps as $key => $step) {
      $all_children = $this->getAllChildren($step);
      if ($key !== $this->currentStep) {
        foreach ($all_children as $child_id) {
          if (isset($form[$child_id])) {
            $form[$child_id]['#access'] = FALSE;
            // @todo need found solution with password.
            if ($child_id === 'account' && isset($form[$child_id]['pass'])) {
              $form[$child_id]['pass']['#required'] = FALSE;
            }
          }
          elseif (isset($form['#fieldgroups'][$child_id])) {
            // Hide nested field groups.
            $form['#fieldgroups'][$child_id]->format_type = 'hidden';
          }
        }
      }
      else {
        foreach ($all_children as $child_id) {
          if (isset($form[$child_id])) {
            $form['actions']['next']['#limit_validation_errors'][] = [$child_id];
          }
        }
      }
    }

  }

}
