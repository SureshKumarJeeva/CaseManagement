<?php

namespace Drupal\associatedps_forms\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CssCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

/**
 * Form for Custom Add Document in create/edit job.
 */
class CustomAddDocument extends FormBase {

  /**
   * Get form ID.
   */
  public function getFormId() {
    return 'custom_add_document';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $userCurrent = \Drupal::currentUser();
    $user = User::load($userCurrent->id());
    $roles = $user->getRoles();
    if ($userCurrent->id() > 0 && $roles[1] !== "agent") {

      $document_title = '';
      $notice_period = '';

      $path = \Drupal::service('path.current')->getPath();
      $nid = (int) filter_var($path, FILTER_SANITIZE_NUMBER_INT);
      $nid = abs($nid);

      if ($nid > 0) {
      }

      $form = [];

      $form['document_title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Title'),
        '#required' => TRUE,
        '#suffix' => '<p id="document-title-validate"></p>',
        '#default_value' => $document_title,
      ];

      $form['notice_period'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Notice Period'),
        '#attributes' => [
          ' type' => 'number',
        ],
        '#required' => FALSE,
        '#default_value' => $notice_period,
      ];

      $form['document_court'] = [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'taxonomy_term',
        '#title' => $this->t('Court name'),
        '#default_value' => '',
        '#tags' => TRUE,
        '#selection_settings' => [
          'target_bundles' => ['court_name'],
        ],
      ];

      $form['document_affidavit'] = [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'node',
        '#title' => $this->t('Affidavite Template'),
        '#default_value' => '',
        '#tags' => TRUE,
        '#selection_handler' => 'default',
        '#selection_settings' => [
          'target_bundles' => ['library'],
        ],
      ];

      $form['job_id'] = [
        '#type' => 'hidden',
        '#value' => $nid,
      ];

      $form['submit'] = [
        '#id' => 'document-for-job',
        '#value' => $this->t("Save"),
        '#type' => 'submit',
        '#ajax' => [
          'callback' => '::submitFormAddDocument',
          'wrapper' => 'wrapper-form-action',
        ],
      ];

      $form['#attached']['library'] = [
        'core/drupal.dialog.ajax',
      ];

      $form['#cache']['max-age'] = 0;
      $form['#attributes']['class'] = ['wrapper-form-add-document'];

      return $form;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitFormAddDocument(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $document_title = $form_state->getValue('document_title');

    if (empty($document_title)) {
      $response->addCommand(new CssCommand('.form-item-document-title input', ['border' => '1px solid red']));
      $response->addCommand(new HtmlCommand('#document-title-validate', 'Please enter a Title.'));
      return $response;
    }
    else {
      $response->addCommand(new CssCommand('.form-item-document-title input', ['border' => '1px solid #b8b8b8', 'border-top-color' => '#999']));
      $response->addCommand(new HtmlCommand('#document-title-validate', ''));
      $response->addCommand(new CloseDialogCommand());
      $values = $form_state->getValues();
      $values = $form_state->getValues();
      if ($values['job_id'] === 0) {
        $redirect_url = '/node/add/job';
      }
      else {
        $redirect_url = '/node/' . $values['job_id'] . '/edit';
      }

      $command = new RedirectCommand($redirect_url);
      $response->addCommand($command);

      /* Node add document */

      $node = Node::create(['type' => 'document']);
      $node->set('title', $values['document_title']);
      $node->set('field_notice_period', $values['notice_period']);
      $node->field_court_name[] = ['target_id' => $values['document_court'][0]['target_id']];
      $node->field_affidavit_template[] = ['target_id' => $values['document_affidavit'][0]['target_id']];
      $node->status = 1;
      $node->save();
      /* End node add client */

      $node_id = $node->id();
      setcookie('create_doc_for_job', $node_id, time() + (86400 * 30), "/");

      $value_submit = [
        'node_doc_id' => $node_id,
        'document_submit' => 1,
        'document_title' => $values['document_title'],
        'notice_period' => $values['notice_period'],
        'court_name' => $values['document_court'][0]['target_id'],
        'affidavit_template' => $values['document_affidavit'][0]['target_id'],
      ];
      user_cookie_save($value_submit);

      return $response;
    }

    if (form_get_errors()) {
      $form_state['rebuild'] = TRUE;
      return $form;
    }
  }

}
