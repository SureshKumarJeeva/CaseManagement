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
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\user\Entity\User;

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

      $job_nid = (int) \Drupal::request()->query->get('job_nid', 0);

      $form = [];

      $form['document_title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Title'),
        '#required' => TRUE,
        '#suffix' => '<p id="document-title-validate"></p>',
      ];

      $form['notice_period'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Notice Period'),
        '#attributes' => [
          ' type' => 'number',
        ],
        '#required' => FALSE,
      ];

      $form['document_court'] = [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'taxonomy_term',
        '#title' => $this->t('Court name'),
        '#tags' => TRUE,
        '#selection_settings' => [
          'target_bundles' => ['court_name'],
        ],
      ];

      $form['document_affidavit'] = [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'node',
        '#title' => $this->t('Affidavite Template'),
        '#tags' => TRUE,
        '#selection_handler' => 'default',
        '#selection_settings' => [
          'target_bundles' => ['library'],
        ],
      ];

      $form['job_id'] = [
        '#type' => 'hidden',
        '#value' => $job_nid,
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

    $response->addCommand(new CssCommand('.form-item-document-title input', ['border' => '1px solid #b8b8b8', 'border-top-color' => '#999']));
    $response->addCommand(new HtmlCommand('#document-title-validate', ''));
    $response->addCommand(new CloseDialogCommand());

    $values = $form_state->getValues();
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

    if ($job_nid > 0) {
      // Edit mode: directly attach the document to the job as a new paragraph.
      $job_node = Node::load($job_nid);
      if ($job_node) {
        $paragraph = Paragraph::create(['type' => 'document']);
        $paragraph->set('field_document', ['target_id' => $node_id]);
        $paragraph->set('field_document_title', $values['document_title']);
        $paragraph->set('field_notice_period', $values['notice_period']);
        $paragraph->set('field_court_name', $court_target_id ? ['target_id' => $court_target_id] : []);
        $paragraph->set('field_affidavit_template', $affidavit_target_id ? ['target_id' => $affidavit_target_id] : []);
        $paragraph->save();
        $job_node->field_documents[] = [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ];
        $job_node->save();
      }
    }
    else {
      // Add mode: use cookies so the presave hook attaches the document
      // when the job is first saved.
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
