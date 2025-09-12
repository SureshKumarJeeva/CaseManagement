<?php

/**
 * @file
 * Hooks defined by Upgrade Status.
 */

use Drupal\Core\Form\FormStateInterface;

 /**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter the operations run on projects on the Upgrade Status UI.
 * 
 * @param array $operations
 *   Batch operations array to be altered.
 * @param \Drupal\Core\Form\FormStateInterface $form_state
 *   The submitted state of the upgrade status form.
 */
function hook_upgrade_status_operations_alter(&$operations) {
  // Duplicate each operation with another one that runs rector on the
  // same extension.
  if (!empty($form_state->getValue('run_rector'))) {
    $keys = array_keys($operations);
    foreach($keys as $key) {
      $operations[] = [
        'update_rector_run_rector_batch',
        [$operations[$key][1][0]]
      ];
    }
  }
}

/**
 * @} End of "addtogroup hooks".
 */
