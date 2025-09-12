<?php

namespace Drupal\role_login_page\Routing;

use Symfony\Component\Routing\Route;

/**
 * Defines dynamic routes.
 */
class RoleLoginRoutes {

  /**
   * {@inheritdoc}
   * Create dynamic routes for the new login pages.
   */
  public function routes() {
    $routes = [];

    $login_menu_arr = db_select('role_login_page_settings', 'rlps')
      ->fields('rlps')
      ->execute()
      ->fetchAll();
    $i = 0;
    foreach ($login_menu_arr as $login_menu_data) {
      // Returns an array of Route objects. 
      $routes['role_login_page.route' . $i] = new Route(
        // Path to attach this route to:
        '/' . $login_menu_data->url,
        // Route defaults:
        [
        '_form' => '\Drupal\role_login_page\Form\RoleLoginForm',
        '_title' => $login_menu_data->page_title,
        'data' => $login_menu_data
        ],
        // Route requirements:
        [
        '_user_is_logged_in' => 'FALSE',
        ]
      );
      $i++;
    }

    return $routes;
  }

}
