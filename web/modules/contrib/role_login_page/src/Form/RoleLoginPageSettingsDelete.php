<?php

/**
   * @file
   * Contains \Drupal\role_login_page\Form\RoleLoginPageSettingsDelete.
    */

namespace Drupal\role_login_page\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Url;

/**
   * Delete login page form.
    */
class RoleLoginPageSettingsDelete extends ConfirmFormBase {

  protected $id;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return '_role_login_page_settings_delete';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete the login page?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromUri('internal:/admin/config/login/role_login_settings/list');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This action cannot be undone. Only do this if you are sure!');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText() {
    return $this->t('Cancel');
  }

  /**
   * 
   * @param array $form
   * @param FormStateInterface $form_state
   * @param type $rlid
   * @return type
   */
  public function buildForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state, $rlid = NULL) {
    $form = [];
    $this->id = $rlid;
    return parent::buildForm($form, $form_state);
  }

  /**
   * 
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function submitForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $url = db_query_range("SELECT url FROM {role_login_page_settings} WHERE rl_id = :rl_id", 0, 1, [
      ':rl_id' => $this->id
      ])->fetchField();
    $deleted = db_delete('role_login_page_settings')
      ->condition('rl_id', $this->id)
      ->execute();
    if ($deleted) {
      _role_login_page_settings_cache_clear($url, 'delete');
    }
  }

}

?>