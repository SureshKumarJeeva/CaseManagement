<?php

/**
   * @file
   * Contains \Drupal\role_login_page\Form\RoleLoginPageSettingsEdit.
    */

namespace Drupal\role_login_page\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Edit login page form.
 */
class RoleLoginPageSettingsEdit extends FormBase {

  protected $rlid;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return '_role_login_page_settings_edit';
  }

  /**
   * 
   * @param array $form
   * @param FormStateInterface $form_state
   * @param type $rl_id
   * @return string
   */
  public function buildForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state, $rl_id = NULL) {
    $login_menu_data = db_select('role_login_page_settings', 'rlps')
      ->fields('rlps')
      ->condition('rl_id', $rl_id)
      ->execute()
      ->fetchObject();
    $roles_arr = \Drupal\user\Entity\Role::loadMultiple();
    foreach ($roles_arr as $role => $rolesObj) {
      $roles[$role] = $rolesObj->get('label');
    }
    if ($login_menu_data) {
      $form['login_page_menu'] = [
        '#type' => 'fieldset',
        '#title' => t('Edit login page'),
        '#collapsible' => FALSE,
      ];
      $form['login_page_menu']['loginmenu_url'] = [
        '#type' => 'textfield',
        '#title' => 'Login page url',
        '#required' => TRUE,
        '#default_value' => $login_menu_data->url,
        '#description' => t('URL should exclude the basepath, i.e, "http://example.com". Add the path that should be used after base path, i.e, "user or admin/newconfig"'),
      ];
      $form['login_page_menu']['username_label'] = [
        '#type' => 'textfield',
        '#title' => 'Username label',
        '#default_value' => $login_menu_data->username_label,
      ];
      $form['login_page_menu']['password_label'] = [
        '#type' => 'textfield',
        '#title' => 'Password label',
        '#default_value' => $login_menu_data->password_label,
      ];
      $form['login_page_menu']['submit_text'] = [
        '#type' => 'textfield',
        '#title' => 'Submit button text',
        '#default_value' => $login_menu_data->submit_text,
      ];
      $form['login_page_menu']['page_title'] = [
        '#type' => 'textfield',
        '#title' => 'Page title',
        '#default_value' => $login_menu_data->page_title,
      ];
      $form['login_page_menu']['redirect_path'] = [
        '#type' => 'textfield',
        '#title' => 'Redirect path',
        '#default_value' => $login_menu_data->redirect_path,
        '#description' => t('Path should exclude the basepath, i.e, "http://example.com". Add the path that should be used after base path, i.e, "user or admin/newconfig"'),
      ];
      $form['login_page_menu']['roles'] = [
        '#type' => 'select',
        '#title' => 'Select the user roles allowed to login through this page : ',
        '#options' => $roles,
        '#multiple' => TRUE,
        '#required' => TRUE,
        '#default_value' => explode(',', $login_menu_data->roles),
      ];
      $form['login_page_menu']['parent_class'] = [
        '#type' => 'textfield',
        '#title' => 'Form parent class',
        '#description' => t('This class will be added to the form element.'),
        '#default_value' => $login_menu_data->parent_class,
      ];
      $form['login_page_menu']['role_mismatch_error_text'] = [
        '#type' => 'textarea',
        '#title' => 'Role mismatch error text',
        '#default_value' => $login_menu_data->role_mismatch_error_text,
      ];
      $form['login_page_menu']['invalid_credentials_error_text'] = [
        '#type' => 'textarea',
        '#title' => 'Invalid credentials error text',
        '#default_value' => $login_menu_data->invalid_credentials_error_text,
      ];
      $this->rlid = $login_menu_data->rl_id;
      $form['login_page_menu']['submit'] = [
        '#type' => 'submit',
        '#value' => 'Update login page',
      ];
      return $form;
    }
    else {
      drupal_set_message(t('Invalid login page ID'), 'warning');
      $redirect = new RedirectResponse(Url::fromUserInput('/admin/config/login/role_login_settings/list')->toString());
      $redirect->send();
    }
  }

  /**
   * 
   * @global type $base_url
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function validateForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {
    global $base_url;
    $rl_id = $this->rlid;
    $url = trim($form_state->getValue(['loginmenu_url']));
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
    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0 || (strpos($url, 'www') === 0 && strpos($url, '.')) || strpos($url, '.')) {
      $form_state->setErrorByName('loginmenu_url', $this->t("@comurl is not a valid URL", [
          '@comurl' => $url
      ]));
    }
    else {
      $menu_exists = \Drupal::service('path.validator')->getUrlIfValid($url);
      $login_page_exists = db_query_range("SELECT 1 FROM {role_login_page_settings} WHERE url = :link_path and rl_id <> :rl_id", 0, 1, [
        ':link_path' => $url,
        ':rl_id' => $rl_id,
        ])->fetchField();

      $current_data_match = db_query_range("SELECT 1 FROM {role_login_page_settings} WHERE url = :link_path and rl_id = :rl_id", 0, 1, [
        ':link_path' => $url,
        ':rl_id' => $rl_id,
        ])->fetchField();
      if ($login_page_exists && !$current_data_match) {
        $form_state->setErrorByName('loginmenu_url', $this->t('The menu URL already exists'));
      }
      elseif (!$login_page_exists && !$current_data_match) {
        if ($menu_exists && $menu_exists->getRouteName()) {
          $form_state->setErrorByName('loginmenu_url', $this->t('The menu URL already exists'));
        }
      }
    }
  }

  /**
   * 
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function submitForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $rl_id = $this->rlid;
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
    db_update('role_login_page_settings')
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
      ->condition('rl_id', $rl_id)
      ->execute();
    _role_login_page_settings_cache_clear($url, 'update');
  }

}

?>