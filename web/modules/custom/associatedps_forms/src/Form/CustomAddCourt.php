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
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;

/**
 * Form for Custom Add/Edit Court in create/edit job.
 */
class CustomAddCourt extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_add_court';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $userCurrent = \Drupal::currentUser();
    $user = User::load($userCurrent->id());
    $roles = $user->getRoles();
    if ($userCurrent->id() > 0 && $roles[1] !== "agent") {

      $court_name = '';

      $path = \Drupal::service('path.current')->getPath();
      $nid = (int) filter_var($path, FILTER_SANITIZE_NUMBER_INT);
      $nid = abs($nid);

      $form = [];

      $form['court_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Court Name'),
        '#required' => TRUE,
        '#suffix' => '<p id="court-name-validate"></p>',
        '#default_value' => $court_name,
      ];

      $form['job_id'] = [
        '#type' => 'hidden',
        '#value' => $nid,
      ];

      $form['submit'] = [
        '#value' => $this->t("Save"),
        '#type' => 'submit',
        '#ajax' => [
          'callback' => '::submitFormAddCourt',
        ],
      ];

      $form['#attached']['library'] = [
        'core/drupal.dialog.ajax',
      ];

      $form['#cache']['max-age'] = 0;
      $form['#attributes']['class'] = ['wrapper-form-add-court'];

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
   * AJAX callback for the court form save button.
   */
  public function submitFormAddCourt(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $values = $form_state->getValues();
    $court_name = trim($values['court_name']);
    $job_id = (int) $values['job_id'];

    if (empty($court_name)) {
      $response->addCommand(new CssCommand('.form-item-court-name input', ['border' => '1px solid red']));
      $response->addCommand(new HtmlCommand('#court-name-validate', 'Please enter a Court Name.'));
      return $response;
    }

    $response->addCommand(new CssCommand('.form-item-court-name input', ['border' => '1px solid #b8b8b8', 'border-top-color' => '#999']));
    $response->addCommand(new HtmlCommand('#court-name-validate', ''));

    // Determine if we should update an existing court or create a new one.
    $court_term = NULL;
    $job_node = NULL;
    if ($job_id > 0) {
      $job_node = Node::load($job_id);
      if ($job_node) {
        $court_ref = $job_node->get('field_court')->getValue();
        if (!empty($court_ref)) {
          $court_term = Term::load($court_ref[0]['target_id']);
        }
      }
    }

    if (!$court_term) {
      $court_term = Term::create([
        'vid' => 'court_name',
      ]);
    }

    $court_term->setName($court_name);
    $court_term->save();

    $term_id = $court_term->id();

    // If editing an existing job, set the court reference and save the job.
    if ($job_node) {
      $job_node->set('field_court', ['target_id' => $term_id]);
      $job_node->save();
    }

    // Only set cookies for new job creation (job_id = 0).
    if ($job_id === 0) {
      user_cookie_save([
        'court_term_id' => $term_id,
        'court_submit' => 1,
      ]);
    }

    // Preserve the active tab (Court Details = index 1) across the redirect.
    setcookie('job_form_active_tab', '1', 0, '/');
    setcookie('job_form_active_page', $job_id > 0 ? (string) $job_id : 'add', 0, '/');

    // Close dialog and redirect back to the job form.
    $response->addCommand(new CloseDialogCommand());

    if ($job_id === 0) {
      $redirect_url = '/node/add/job';
    }
    else {
      $redirect_url = '/node/' . $job_id . '/edit';
    }
    $response->addCommand(new RedirectCommand($redirect_url));

    return $response;
  }

}
