<?php

/**
 * @file
 * Contains \Drupal\role_login_page\Controller\RoleLoginPageController.
 **/

namespace Drupal\role_login_page\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Database\Database;

/**
 * Login pages list.
 **/
class RoleLoginPageController extends ControllerBase {

  protected $connection;

  /**
   * RoleLoginPageController constructor.
   */
  public function __construct() {
    $this->connection = Database::getConnection();
  }
  /**
   *
   * @return type
   * @global type $base_url
   */
  public function _role_login_page_settings_list() {
    global $base_url;
    // You will need to use `\Drupal\core\Database\Database::getConnection()` if you do not yet have access to the container here.
    $login_menu_arr = $this->connection
      ->select('role_login_page_settings', 'rlps')
      ->fields('rlps')
      ->execute()
      ->fetchAll();
    $rows = [];
    $url_options = [
      'query' => ['destination' => 'admin/config/login/role_login_settings/list'],
    ];
    foreach ($login_menu_arr as $login_menu_data) {
      $roles_arr = explode(',', $login_menu_data->roles);
      $username_label = (($login_menu_data->username_label) ? $login_menu_data->username_label : '-');
      $password_label = (($login_menu_data->password_label) ? $login_menu_data->password_label : '-');
      $edit_url = Link::fromTextAndUrl(t('Edit'), Url::fromUri('internal:/admin/config/login/role_login_settings/edit/' . $login_menu_data->rl_id, $url_options))
        ->toString();
      $delete_url = Link::fromTextAndUrl(t('Delete'), Url::fromUri('internal:/admin/config/login/role_login_settings/delete/' . $login_menu_data->rl_id, $url_options))
        ->toString();
      $build_link_action = [
        'action_edit' => [
          '#type' => 'html_tag',
          '#value' => $edit_url,
          '#tag' => 'div',
          '#attributes' => ['class' => ['action-edit']],
        ],
        'action_delete' => [
          '#type' => 'html_tag',
          '#value' => $delete_url,
          '#tag' => 'div',
          '#attributes' => ['class' => ['action-delete']],
        ],
      ];
      $rows[] = [
        $login_menu_data->url,
        $login_menu_data->roles,
        $username_label,
        $password_label,
        \Drupal::service('renderer')->render($build_link_action),
      ];
    }
    $header = [
      'Login url',
      'Roles',
      'Username label',
      'Password label',
      'Operations',
    ];
    $output = '<div></div>';
    $table = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#attributes' => [
        'id' => 'my-module-table',
      ],
    ];
    $output .= \Drupal::service('renderer')->render($table);
    return ['#markup' => $output];
  }

}
