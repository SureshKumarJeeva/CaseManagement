<?php

namespace Drupal\associatedps_forms\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CssCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\user\Entity\User;

/**
 * Form for adding a new user from the job add/edit page.
 */
class CustomAddUser extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_add_user';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $userCurrent = \Drupal::currentUser();
    $user = User::load($userCurrent->id());
    $roles = $user->getRoles();
    if ($userCurrent->id() > 0 && $roles[1] !== "agent") {

      $path = \Drupal::service('path.current')->getPath();
      $nid = (int) filter_var($path, FILTER_SANITIZE_NUMBER_INT);
      $nid = abs($nid);

      $form = [];

      $form['username'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Username'),
        '#required' => TRUE,
        '#suffix' => '<p id="username-validate"></p>',
      ];

      $form['email'] = [
        '#type' => 'email',
        '#title' => $this->t('Email'),
        '#required' => TRUE,
        '#suffix' => '<p id="email-validate"></p>',
      ];

      $form['password'] = [
        '#type' => 'password',
        '#title' => $this->t('Password'),
        '#required' => TRUE,
        '#suffix' => '<p id="password-validate"></p>',
      ];

      $form['first_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('First Name'),
      ];

      $form['last_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Last Name'),
      ];

      $form['phone'] = [
        '#type' => 'tel',
        '#title' => $this->t('Phone'),
      ];

      $form['display_email'] = [
        '#type' => 'email',
        '#title' => $this->t('Display E-mail'),
      ];

      $form['role'] = [
        '#type' => 'select',
        '#title' => $this->t('Role'),
        '#options' => $this->getAvailableRoles(),
        '#default_value' => 'agent',
      ];

      $form['job_id'] = [
        '#type' => 'hidden',
        '#value' => $nid,
      ];

      $form['submit'] = [
        '#value' => $this->t("Save"),
        '#type' => 'submit',
        '#ajax' => [
          'callback' => '::submitFormAddUser',
        ],
      ];

      $form['#attached']['library'] = [
        'core/drupal.dialog.ajax',
      ];

      $form['#cache']['max-age'] = 0;
      $form['#attributes']['class'] = ['wrapper-form-add-user'];

      return $form;
    }
  }

  /**
   * Get available user roles for the select dropdown.
   */
  protected function getAvailableRoles() {
    $roles = user_roles(TRUE);
    $options = [];
    foreach ($roles as $role_id => $role) {
      if ($role_id !== 'authenticated') {
        $options[$role_id] = $role->label();
      }
    }
    return $options;
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
   * AJAX callback for the user form save button.
   */
  public function submitFormAddUser(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $values = $form_state->getValues();
    $username = trim($values['username']);
    $email = trim($values['email']);
    $password = $values['password'];
    $first_name = trim($values['first_name']);
    $last_name = trim($values['last_name']);
    $phone = trim($values['phone']);
    $display_email = trim($values['display_email']);
    $role = $values['role'];
    $job_id = (int) $values['job_id'];

    // Validate required fields.
    if (empty($username)) {
      $response->addCommand(new CssCommand('.form-item-username input', ['border' => '1px solid red']));
      $response->addCommand(new HtmlCommand('#username-validate', 'Please enter a Username.'));
      return $response;
    }

    if (empty($email)) {
      $response->addCommand(new CssCommand('.form-item-email input', ['border' => '1px solid red']));
      $response->addCommand(new HtmlCommand('#email-validate', 'Please enter an Email.'));
      return $response;
    }

    if (empty($password)) {
      $response->addCommand(new CssCommand('.form-item-password input', ['border' => '1px solid red']));
      $response->addCommand(new HtmlCommand('#password-validate', 'Please enter a Password.'));
      return $response;
    }

    // Check if username already exists.
    if (user_load_by_name($username)) {
      $response->addCommand(new CssCommand('.form-item-username input', ['border' => '1px solid red']));
      $response->addCommand(new HtmlCommand('#username-validate', 'This username is already taken.'));
      return $response;
    }

    // Check if email already exists.
    if (user_load_by_mail($email)) {
      $response->addCommand(new CssCommand('.form-item-email input', ['border' => '1px solid red']));
      $response->addCommand(new HtmlCommand('#email-validate', 'This email is already in use.'));
      return $response;
    }

    // Clear validation messages.
    $response->addCommand(new CssCommand('.form-item-username input', ['border' => '1px solid #b8b8b8', 'border-top-color' => '#999']));
    $response->addCommand(new HtmlCommand('#username-validate', ''));
    $response->addCommand(new CssCommand('.form-item-email input', ['border' => '1px solid #b8b8b8', 'border-top-color' => '#999']));
    $response->addCommand(new HtmlCommand('#email-validate', ''));
    $response->addCommand(new CssCommand('.form-item-password input', ['border' => '1px solid #b8b8b8', 'border-top-color' => '#999']));
    $response->addCommand(new HtmlCommand('#password-validate', ''));

    // Create the new user.
    $new_user = User::create();
    $new_user->setUsername($username);
    $new_user->setEmail($email);
    $new_user->setPassword($password);
    $new_user->activate();
    $new_user->addRole($role);

    // Set address field (given_name, family_name).
    if (!empty($first_name) || !empty($last_name)) {
      $new_user->set('field_address', [
        'country_code' => 'AU',
        'given_name' => $first_name,
        'family_name' => $last_name,
      ]);
    }

    // Set phone field.
    if (!empty($phone)) {
      $new_user->set('field_phone', $phone);
    }

    // Set display email field.
    if (!empty($display_email)) {
      $new_user->set('field_e_mail', $display_email);
    }

    $new_user->save();
    $new_uid = $new_user->id();

    // If editing an existing job, set the allocated_to field and save.
    if ($job_id > 0) {
      $job_node = \Drupal::entityTypeManager()->getStorage('node')->load($job_id);
      if ($job_node) {
        $job_node->set('field_allocated_to', ['target_id' => $new_uid]);
        $job_node->save();
      }
    }

    // For new jobs (job_id = 0), set cookies so form_alter can populate.
    if ($job_id === 0) {
      user_cookie_save([
        'new_user_uid' => $new_uid,
        'new_user_submit' => 1,
      ]);
    }

    // Determine the Job Status tab index dynamically from the form display
    // config, so this doesn't break if tabs are reordered.
    $tab_index = 0;
    $form_display = \Drupal::entityTypeManager()
      ->getStorage('entity_form_display')
      ->load('node.job.default');
    if ($form_display) {
      $groups = $form_display->getThirdPartySettings('field_group');
      if (!empty($groups['group_job']['children'])) {
        $index = array_search('group_job__status', $groups['group_job']['children']);
        if ($index !== FALSE) {
          $tab_index = $index;
        }
      }
    }
    setcookie('job_form_active_tab', (string) $tab_index, 0, '/');
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
