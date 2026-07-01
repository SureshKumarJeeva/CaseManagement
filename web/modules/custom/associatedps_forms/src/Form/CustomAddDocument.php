<?php

namespace Drupal\associatedps_forms\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CssCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\node\Entity\Node;
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

      // Court name + Affidavit Template as Chosen (searchable/scrollable) selects
      // instead of autocomplete. Options are loaded from the same sources the
      // autocomplete targeted: 'court_name' taxonomy terms and 'library' nodes.
      $court_options = ['_none' => $this->t('- None -')];
      $court_terms = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadByProperties(['vid' => 'court_name']);
      foreach ($court_terms as $term) {
        $court_options[$term->id()] = $term->label();
      }
      natcasesort($court_options);

      $library_options = ['_none' => $this->t('- None -')];
      $library_nids = \Drupal::entityQuery('node')
        ->condition('type', 'library')
        ->condition('status', 1)
        ->sort('title')
        ->accessCheck(TRUE)
        ->execute();
      foreach (Node::loadMultiple($library_nids) as $library) {
        $library_options[$library->id()] = $library->label();
      }

      $form['document_court'] = [
        '#type' => 'select',
        '#title' => $this->t('Court name'),
        '#options' => $court_options,
        '#required' => FALSE,
        '#attributes' => ['class' => ['chosen-enable']],
      ];

      $form['document_affidavit'] = [
        '#type' => 'select',
        '#title' => $this->t('Affidavite Template'),
        '#options' => $library_options,
        '#required' => FALSE,
        '#attributes' => ['class' => ['chosen-enable']],
      ];

      $form['job_id'] = [
        '#type' => 'hidden',
        '#value' => $job_nid,
      ];

      // DOM id of the document paragraph's Document <select> that opened this
      // modal. On save the new document is appended + selected in it.
      $form['target_select'] = [
        '#type' => 'hidden',
        '#value' => \Drupal::request()->query->get('target', ''),
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
        // Apply Chosen to the Court name + Affidavit Template selects.
        'chosen/drupal.chosen',
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

    $values = $form_state->getValues();

    // Court and affidavit are now Chosen selects: the value is a scalar id (or
    // '_none').
    $court_target_id = (!empty($values['document_court']) && $values['document_court'] !== '_none') ? $values['document_court'] : NULL;
    $affidavit_target_id = (!empty($values['document_affidavit']) && $values['document_affidavit'] !== '_none') ? $values['document_affidavit'] : NULL;

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

    // Inject the new document into the originating paragraph's Document <select>
    // (id passed in via ?target=) and select it, then refresh Chosen. This fills
    // that specific document section without a page reload. Works for both add
    // and edit job forms — the job is saved normally by the user afterwards.
    $target = trim((string) $form_state->getValue('target_select'));
    if ($target !== '') {
      // $target tags this paragraph's Document <select> via data-doc-select.
      // The jQuery plugin (custom.js) appends the new doc as a selected option
      // and dispatches NATIVE chosen:updated + change events (this Chosen build
      // and the notice-period #ajax both listen natively, so jQuery .trigger()
      // would not reach them).
      $selector = 'select[data-doc-select="' . $target . '"]';
      $response->addCommand(new InvokeCommand($selector, 'apsSelectDocument', [(string) $node_id, $values['document_title']]));
    }

    $response->addCommand(new CloseDialogCommand());

    return $response;
  }

}
