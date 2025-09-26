<?php

/**
 * @file
 * Hooks provided by the Simple Multistep module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter the default controller \Drupal\simple_multistep\MultistepController.
 *
 * @param array $form
 *   The current form being processed.
 */
function hook_simple_multistep_controller_alter(array &$form) {
  // Alter controller for steps.
  $form['#multistep_controller'] = '\Drupal\my_module\CustomMultiStepController';
}

/**
 * @} End of "addtogroup hooks".
 */
