<?php

/**
 * @file
 * Contains \Drupal\role_login_page\Form\RoleLoginForm.
 **/

namespace Drupal\role_login_page\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Database\Database;

/**
 * Login form.
 **/
class RoleLoginForm extends FormBase {

  protected $login_settings_data;

  protected $connection;

    /**
     * RoleLoginForm constructor.
     */
    public function __construct() {
      $this->connection = Database::getConnection();
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return '_role_login_page_form';
  }

  /**
   *
   * @param array $form
   * @param FormStateInterface $form_state
   * @param type $data
   *
   * @return type
   * New dynamic login form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $rl_id = NULL) {
    if ($rl_id) {
      $data = $this->connection->select('role_login_page_settings', 'rlps')
      ->fields('rlps')
      ->condition('rlps.rl_id', $rl_id)
      ->execute()
      ->fetchObject();
      if ($data) {
      $this->login_settings_data = $data;
      $username_label = ($data->username_label) ? Html::escape($data->username_label) : 'User Name or Email';
      $password_label = ($data->password_label) ? Html::escape($data->password_label) : 'Password';
      $submit_btn_label = ($data->submit_text) ? Html::escape($data->submit_text) : 'Login';
      $parent_class = ($data->parent_class) ? Html::escape($data->parent_class) : '';
      if ($parent_class) {
        $form['#attributes']['class'][] = $parent_class;
      }
      $form['name'] = [
        '#type' => 'textfield',
        '#title' => t($username_label),
        '#required' => TRUE,
      ];
      $form['pass'] = [
        '#type' => 'password',
        '#title' => t($password_label),
        '#required' => TRUE,
      ];
      $form['submit'] = [
        '#type' => 'submit',
        '#value' => t($submit_btn_label),
      ];
      return $form;
    }
    \Drupal::messenger()->addWarning(t('There are technical difficulties in generating the page. Please check the logs.'));
    \Drupal::logger('RoleLoginForm')->error("No data found for the ID $rl_id");
  }
  else {
    \Drupal::messenger()->addWarning(t('There are technical difficulties in generating the page. Please check the logs.'));
    \Drupal::logger('RoleLoginForm')->error("No RL ID found. Please check the routes.");
  }
}

  /**
   *
   * @param array $form
   * @param FormStateInterface $form_state
   * Validate new login form.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $loginmenu_data = $this->login_settings_data;
    $roles = $loginmenu_data->roles;
    $roles = explode(',', $roles);
    $role_mismatch_error = ($loginmenu_data->role_mismatch_error_text) ? Html::escape($loginmenu_data->role_mismatch_error_text) : 'You do not have permissions to login through this page.';
    $invalid_credentials_error = ($loginmenu_data->invalid_credentials_error_text) ? Html::escape($loginmenu_data->invalid_credentials_error_text) : 'Invalid credentials.';
    $username = $form_state->getValue(['name']);
    $password = $form_state->getValue(['pass']);
    if ($uid = \Drupal::service("user.auth")
      ->authenticate($username, $password)) {
      if (!_role_login_page_validate_login_roles($uid, $roles)) {
        $form_state->setErrorByName('name', $this->t($role_mismatch_error));
      }
    }
    else {
      $form_state->setErrorByName('name', $this->t($invalid_credentials_error));
    }
  }

  /**
   *
   * @param array $form
   * @param FormStateInterface $form_state
   *
   * @return boolean
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $loginmenu_data = $this->login_settings_data;
    $roles = $loginmenu_data->roles;
    $roles = explode(',', $roles);
    $role_mismatch_error = ($loginmenu_data->role_mismatch_error_text) ? Html::escape($loginmenu_data->role_mismatch_error_text) : 'You do not have permissions to login through this page.';
    $invalid_credentials_error = ($loginmenu_data->invalid_credentials_error_text) ? Html::escape($loginmenu_data->invalid_credentials_error_text) : 'Invalid credentials.';
    $username = $form_state->getValue(['name']);
    $password = $form_state->getValue(['pass']);
    $redirect_path = ($loginmenu_data->redirect_path) ? $loginmenu_data->redirect_path : '';
    if ($uid = \Drupal::service("user.auth")
      ->authenticate($username, $password)) {
      if (_role_login_page_validate_login_roles($uid, $roles)) {
        $user = User::load($uid);
        user_login_finalize($user);
        if (empty($redirect_path) || $redirect_path == "/" || $redirect_path == "<front>") {
		  \Drupal::service('request_stack')->getCurrentRequest()->query->set('destination', '/');
        }
        else {
		  \Drupal::service('request_stack')->getCurrentRequest()->query->set('destination', $redirect_path);
        }
        return;
      }
      else {
        $form_state->setErrorByName('name', $this->t($role_mismatch_error));
      }
    }
    else {
      form_set_error('name', $this->t($invalid_credentials_error));
      return FALSE;
    }
  }

}

?>
