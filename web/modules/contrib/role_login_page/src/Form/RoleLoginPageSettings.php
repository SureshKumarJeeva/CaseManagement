<?php

/**
   * @file
   * Contains \Drupal\role_login_page\Form\RoleLoginPageSettings.
    */

namespace Drupal\role_login_page\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

/**
   * Add login page form.
    */
class RoleLoginPageSettings extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return '_role_login_page_settings';
  }

  /**
   * 
   * @param array $form
   * @param FormStateInterface $form_state
   * @return string
   */
  public function buildForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $roles_arr = \Drupal\user\Entity\Role::loadMultiple();
    foreach ($roles_arr as $role => $rolesObj) {
      $roles[$role] = $rolesObj->get('label');
    }
    $form['login_page_menu'] = [
      '#type' => 'fieldset',
      '#title' => t('Add login page'),
      '#collapsible' => FALSE,
    ];
    $form['login_page_menu']['loginmenu_url'] = [
      '#type' => 'textfield',
      '#title' => 'Login page url',
      '#required' => TRUE,
      '#description' => t('URL should exclude the basepath, i.e, "http://example.com". Add the path that should be used after base path, i.e, "user or admin/newconfig"'),
    ];
    $form['login_page_menu']['username_label'] = [
      '#type' => 'textfield',
      '#title' => 'Username label',
    ];
    $form['login_page_menu']['password_label'] = [
      '#type' => 'textfield',
      '#title' => 'Password label',
    ];
    $form['login_page_menu']['submit_text'] = [
      '#type' => 'textfield',
      '#title' => 'Submit button text',
    ];
    $form['login_page_menu']['page_title'] = [
      '#type' => 'textfield',
      '#title' => 'Page title',
    ];
    $form['login_page_menu']['redirect_path'] = [
      '#type' => 'textfield',
      '#title' => 'Redirect path',
      '#description' => t('Path should exclude the basepath, i.e, "http://example.com". Add the path that should be used after base path, i.e, "user or admin/newconfig"'),
    ];
    $form['login_page_menu']['roles'] = [
      '#type' => 'select',
      '#title' => 'Select the user roles allowed to login through this page : ',
      '#options' => $roles,
      '#multiple' => TRUE,
      '#required' => TRUE,
    ];
    $form['login_page_menu']['parent_class'] = [
      '#type' => 'textfield',
      '#title' => 'Form parent class',
      '#description' => t('This class will be added to the form element.'),
    ];
    $form['login_page_menu']['role_mismatch_error_text'] = [
      '#type' => 'textarea',
      '#title' => 'Role mismatch error text',
    ];
    $form['login_page_menu']['invalid_credentials_error_text'] = [
      '#type' => 'textarea',
      '#title' => 'Invalid credentials error text',
    ];
    $form['login_page_menu']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Create login page',
    ];
    return $form;
  }

  /**
   * 
   * @global type $base_url
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function validateForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {
    global $base_url;
    $url = trim($form_state->getValue(['loginmenu_url']));
    $complete_url = $base_url . '/' . $url;
    $complete_url = filter_var($complete_url, FILTER_SANITIZE_URL);
    $replacements = ['!', '*', "(", ")", ";", "@", "+", "$", ",", "[", "]"];
    $complete_url = str_replace($replacements, '', $complete_url);
    if (!filter_var($complete_url, FILTER_VALIDATE_URL)) {
      $form_state->setErrorByName('loginmenu_url', $this->t("@comurl is not a valid URL", [
          '@comurl' => $complete_url
      ]));
    }
    $menu_exists = \Drupal::service('path.validator')->getUrlIfValid($url);
    if ($menu_exists) {
      $form_state->setErrorByName('loginmenu_url', $this->t('The menu URL already exists'));
    }
    $redirect_path = trim($form_state->getValue(['redirect_path']));
    if ($redirect_path) {
      $redirect_path_exists = \Drupal::service('path.validator')->getUrlIfValid($redirect_path);
      if ($redirect_path_exists) {
        if (!$redirect_path_exists->getRouteName()) {
          $form_state->setErrorByName('redirect_path', $this->t('Please enter a valid redirect path.'));
        }
      }
      else {
        $form_state->setErrorByName('redirect_path', $this->t('Please enter a valid redirect path.'));
      }
    }
  }

  /**
   * 
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function submitForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $url = trim($form_state->getValue(['loginmenu_url']));
    $replacements = [
      '!',
      '*',
      "(",
      ")",
      ";",
      ":",
      "@",
      "+",
      "$",
      ",",
      "[",
      "]",
      " ",
    ];
    $url = str_replace($replacements, '-', $url);
    $username_label = trim($form_state->getValue(['username_label']));
    $password_label = trim($form_state->getValue(['password_label']));
    $submit_text = trim($form_state->getValue(['submit_text']));
    $page_title = trim($form_state->getValue(['page_title']));
    $redirect_path = trim($form_state->getValue(['redirect_path']));
    $role_mismatch_error_text = trim($form_state->getValue(['role_mismatch_error_text']));
    $invalid_credentials_error_text = trim($form_state->getValue(['invalid_credentials_error_text']));
    $parent_class = $form_state->getValue(['parent_class']);
    $roles = $form_state->getValue(['roles']);
    $roles = implode(',', $roles);
    $add_login_url = db_insert('role_login_page_settings')
      ->fields([
        "url" => $url,
        "username_label" => $username_label,
        "password_label" => $password_label,
        "submit_text" => $submit_text,
        "page_title" => $page_title,
        "parent_class" => $parent_class,
        "redirect_path" => $redirect_path,
        "role_mismatch_error_text" => $role_mismatch_error_text,
        "invalid_credentials_error_text" => $invalid_credentials_error_text,
        "roles" => $roles,
      ])
      ->execute();
    if ($add_login_url) {
      _role_login_page_settings_cache_clear($url, 'add');
    }
  }

}

?>