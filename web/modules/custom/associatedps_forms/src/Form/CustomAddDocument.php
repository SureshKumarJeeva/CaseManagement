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
use Drupal\taxonomy\Entity\Term;

/**
 * Form for Custom Add/Edit Document in create/edit job.
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
      $document_court = NULL;
      $document_affidavit = NULL;

      $path = \Drupal::service('path.current')->getPath();
      $doc_id = (int) filter_var($path, FILTER_SANITIZE_NUMBER_INT);
      $doc_id = abs($doc_id);
      $job_nid = (int) \Drupal::request()->query->get('job_nid', 0);

      // Load existing document data for edit mode.
      if ($doc_id > 0) {
        $doc_node = Node::load($doc_id);
        if ($doc_node && $doc_node->getType() === 'document') {
          $document_title = $doc_node->getTitle();
          $notice_period = $doc_node->field_notice_period->value ?? '';
          if ($doc_node->field_court_name->target_id) {
            $document_court = Term::load($doc_node->field_court_name->target_id);
          }
          if ($doc_node->field_affidavit_template->target_id) {
            $document_affidavit = Node::load($doc_node->field_affidavit_template->target_id);
          }
        }
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
        '#default_value' => $document_court,
        '#tags' => TRUE,
        '#selection_settings' => [
          'target_bundles' => ['court_name'],
        ],
      ];

      $form['document_affidavit'] = [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'node',
        '#title' => $this->t('Affidavite Template'),
        '#default_value' => $document_affidavit,
        '#tags' => TRUE,
        '#selection_handler' => 'default',
        '#selection_settings' => [
          'target_bundles' => ['library'],
        ],
      ];

      $form['doc_id'] = [
        '#type' => 'hidden',
        '#value' => $doc_id,
      ];

      $form['job_id'] = [
        '#type' => 'hidden',
        '#value' => $job_nid,
      ];

      $form['submit'] = [
        '#id' => 'document-for-job',
        '#value' => $doc_id > 0 ? $this->t("Update") : $this->t("Save"),
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

    $response->addCommand(new CssCommand('.form-item-document-title input', ['border' => '1px solid #b8b8b8', 'border-top-color' => '#999']));
    $response->addCommand(new HtmlCommand('#document-title-validate', ''));
    $response->addCommand(new CloseDialogCommand());

    $values = $form_state->getValues();
    $doc_id = (int) $values['doc_id'];
    $job_nid = (int) $values['job_id'];

    // Determine redirect URL.
    if ($job_nid === 0) {
      $redirect_url = '/node/add/job';
    }
    else {
      $redirect_url = '/node/' . $job_nid . '/edit';
    }
    $response->addCommand(new RedirectCommand($redirect_url));

    // Get court and affidavit values safely.
    $court_target_id = !empty($values['document_court'][0]['target_id']) ? $values['document_court'][0]['target_id'] : NULL;
    $affidavit_target_id = !empty($values['document_affidavit'][0]['target_id']) ? $values['document_affidavit'][0]['target_id'] : NULL;

    if ($doc_id > 0) {
      // Update existing document node.
      $node = Node::load($doc_id);
      if ($node) {
        $node->set('title', $values['document_title']);
        $node->set('field_notice_period', $values['notice_period']);
        $node->set('field_court_name', $court_target_id ? ['target_id' => $court_target_id] : []);
        $node->set('field_affidavit_template', $affidavit_target_id ? ['target_id' => $affidavit_target_id] : []);
        $node->save();
      }
    }
    else {
      // Create new document node.
      $node = Node::create(['type' => 'document']);
      $node->set('title', $values['document_title']);
      $node->set('field_notice_period', $values['notice_period']);
      if ($court_target_id) {
        $node->field_court_name[] = ['target_id' => $court_target_id];
      }
      if ($affidavit_target_id) {
        $node->field_affidavit_template[] = ['target_id' => $affidavit_target_id];
      }
      $node->status = 1;
      $node->save();

      $node_id = $node->id();
      setcookie('create_doc_for_job', $node_id, time() + (86400 * 30), "/");

      $value_submit = [
        'node_doc_id' => $node_id,
        'document_submit' => 1,
        'document_title' => $values['document_title'],
        'notice_period' => $values['notice_period'],
        'court_name' => $court_target_id ?? '',
        'affidavit_template' => $affidavit_target_id ?? '',
      ];
      user_cookie_save($value_submit);
    }

    return $response;
  }

}
