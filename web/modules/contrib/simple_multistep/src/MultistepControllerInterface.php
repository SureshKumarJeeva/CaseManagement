<?php

namespace Drupal\simple_multistep;

/**
 * Provides an interface for simple_multistep controller.
 */
interface MultistepControllerInterface {

  /**
   * Prepare Multistep Form.
   *
   * @param array $form
   *   Reference to form.
   */
  public function rebuildForm(array &$form): void;

}
