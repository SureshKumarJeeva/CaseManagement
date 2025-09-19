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
use Drupal\Core\Database\Database;

/**
 * Delete login page form.
 */
class RoleLoginPageSettingsDelete extends ConfirmFormBase {

  protected $id;
  protected $connection;

  /**
   * RoleLoginPageSettingsDelete constructor.
   */
  public function __construct() {
    $this->connection = Database::getConnection();
  }

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
  public function buildForm(array $form, FormStateInterface $form_state, $rlid = NULL) {
    $this->id = $rlid;
    return parent::buildForm($form, $form_state);
  }

  /**
   *
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $url_query = $this->connection->select('role_login_page_settings', 'rlps');
    $url_query->fields('rlps', ['url']);
    $url_query->condition('rl_id', $this->id);
    $url = $url_query->execute()->fetchObject();
    $deleted = $this->connection->delete('role_login_page_settings')
      ->condition('rl_id', $this->id)
      ->execute();
    if ($deleted) {
      _role_login_page_settings_cache_clear($url->url, 'delete');
    }
  }

}

?>
