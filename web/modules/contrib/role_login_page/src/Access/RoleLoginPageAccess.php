<?php

namespace Drupal\role_login_page\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

class RoleLoginPageAccess {

  /**
   * A custom access check.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   * Run access checks for this account.
   */
  public function access(AccountInterface $account) {

    return $account->isAnonymous() ? AccessResult::allowed() : AccessResult::forbidden();
  }

}
