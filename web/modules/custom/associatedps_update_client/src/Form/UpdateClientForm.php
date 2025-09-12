<?php

namespace Drupal\associatedps_update_client\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\associatedps_email_template\Controller\EmailTemplateController;
use Drupal\node\Entity\Node;

/**
 * Form for library template.
 */
class UpdateClientForm extends FormBase {

  /**
   * Get form ID.
   */
  public function getFormId() {
    return 'update_client_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $cat_id = NULL, $nid = NULL) {

    $form = [];
    $form['#prefix'] = "<div class='update-client-form'>";
    $form['#suffix'] = "</div>";
    $job_node = Node::load($nid);
    $client_id = $job_node->get('field_client')->getValue();
    $client_id = reset($client_id);
    $client = Node::load($client_id['target_id']);

    $form['client_update'] = [
      '#module' => 'text',
      '#type' => 'text_format',
      '#title' => 'Client Update',
      '#format' => 'full_html',
      '#rows' => 20,
    ];

    $form['job_ref'] = [
      '#type' => 'hidden',
      '#value' => $nid,
    ];

    $form['job_number'] = [
      '#type' => 'hidden',
      '#value' => $job_node->title->value,
    ];

    $form['client_name'] = [
      '#type' => 'hidden',
      '#value' => $client->title->value,
    ];

    $form['submit'] = [
      '#value' => $this->t("Update"),
      '#type' => 'submit',
    ];

    return $form;
  }

  /**
   * SubmitForm().
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    /* Node add client_update */
    $values = $form_state->getValues();

    $node = Node::create(['type' => 'client_update']);
    $node->title = 'Client ' . $values['client_name'] . ' updated on ' . $values['job_number'];
    $node->field_job_reference[] = ['target_id' => $values['job_ref']];
    $node->field_client_update->value = $values['client_update']['value'];
    $node->field_client_update->format = $values['client_update']['format'];
    $node->field_client_update_on->value = date('Y-m-d\Th:i:s', time());
    $node->save();
    $nid = $node->nid->value;

    EmailTemplateController::createEmailTemplate($nid, $values['job_ref']);
  }

}
