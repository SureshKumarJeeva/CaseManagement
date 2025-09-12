<?php

namespace Drupal\associatedps_forms\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Controller\ControllerBase;

/**
 * ModalFormExampleController class.
 */
class CustomAjaxModalController extends ControllerBase {

  /**
   * Callback for opening the modal form.
   */
  public function openModalContent() {
    $entity = \Drupal::entityTypeManager()->getStorage('node')->load(3);
    $output = \Drupal::entityManager()->getViewBuilder('node')->view($entity);
    // Add an AJAX command to open a modal dialog with the form as the content.
    // return new Response(render($output));
    $response = new AjaxResponse();
    $response->addCommand(new OpenModalDialogCommand($entity->title->value, $output, ['width' => '800']));
    return $response;
  }

}
