<?php

/**
   * @file
   * Contains \Drupal\role_login_page\Form\RoleLoginForm.
    */

namespace Drupal\role_login_page\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
   * Login form.
    */
class RoleLoginForm extends FormBase {

  protected $login_settings_data;

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
   * @return type
   * New dynamic login form.
   */
  public function buildForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state, $data = NULL) {
    if ($data) {
      $this->login_settings_data = $data;
      $username_label = ($data->username_label) ? \Drupal\Component\Utility\Html::escape($data->username_label) : 'User Name or Email';
      $password_label = ($data->password_label) ? \Drupal\Component\Utility\Html::escape($data->password_label) : 'Password';
      $submit_btn_label = ($data->submit_text) ? \Drupal\Component\Utility\Html::escape($data->submit_text) : 'Login';
      $parent_class = ($data->parent_class) ? \Drupal\Component\Utility\Html::escape($data->parent_class) : '';
      if ($parent_class) {
        $form['#attributes']['class'][] = $parent_class;
      }
      $form['name'] = array(
        '#type' => 'textfield',
        '#title' => t($username_label),
        '#required' => TRUE,
      );
      $form['pass'] = array(
        '#type' => 'password',
        '#title' => t($password_label),
        '#required' => TRUE,
      );
      $form['submit'] = array(
        '#type' => 'submit',
        '#value' => t($submit_btn_label),
      );
      return $form;
    }
    else {
      drupal_set_message(t('Invalid login page ID'), 'warning');
      drupal_goto('admin/config/login/role_login_settings/list');
    }
  }

  /**
   * 
   * @param array $form
   * @param FormStateInterface $form_state
   * Validate new login form.
   */
  public function validateForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $loginmenu_data = $this->login_settings_data;
    $roles = $loginmenu_data->roles;
    $roles = explode(',', $roles);
    $role_mismatch_error = ($loginmenu_data->role_mismatch_error_text) ? \Drupal\Component\Utility\Html::escape($loginmenu_data->role_mismatch_error_text) : 'You do not have permissions to login through this page.';
    $invalid_credentials_error = ($loginmenu_data->invalid_credentials_error_text) ? \Drupal\Component\Utility\Html::escape($loginmenu_data->invalid_credentials_error_text) : 'Invalid credentials.';
    $username = $form_state->getValue(['name']);
    $password = $form_state->getValue(['pass']);
    if ($uid = \Drupal::service("user.auth")->authenticate($username, $password)) {
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
   * @return boolean
   */
  public function submitForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $loginmenu_data = $this->login_settings_data;
    $roles = $loginmenu_data->roles;
    $roles = explode(',', $roles);
    $role_mismatch_error = ($loginmenu_data->role_mismatch_error_text) ? \Drupal\Component\Utility\Html::escape($loginmenu_data->role_mismatch_error_text) : 'You do not have permissions to login through this page.';
    $invalid_credentials_error = ($loginmenu_data->invalid_credentials_error_text) ? \Drupal\Component\Utility\Html::escape($loginmenu_data->invalid_credentials_error_text) : 'Invalid credentials.';
    $username = $form_state->getValue(['name']);
    $password = $form_state->getValue(['pass']);
    $redirect_path = ($loginmenu_data->redirect_path) ? $loginmenu_data->redirect_path : '';
    if ($uid = \Drupal::service("user.auth")->authenticate($username, $password)) {
      if (_role_login_page_validate_login_roles($uid, $roles)) {
        $user = \Drupal\user\Entity\User::load($uid);
        user_login_finalize($user);
        if ($redirect_path == "/" || $redirect_path == "<front>") {
          $url = "";
          $redirect = new RedirectResponse(Url::fromUserInput('/' . $url)->toString());
        }
        else {
          $redirect = new RedirectResponse(Url::fromUserInput('/' . $redirect_path)->toString());
        }
        $redirect->send();
        return TRUE;
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