
CONTENTS OF THIS FILE
---------------------
   
 * Introduction
 * Requirements
 * Installation
 * Configuration
 * Maintainers


INTRODUCTION
------------

This module is designed to create multiple login pages based on roles.
The new login page will have everything configurable from the backend, i.e, "username field label", "password field label", "Error messages" and more.
For example : If the new login page will have role "A" assigned to it then the users with role "A" can only login through this page.

REQUIREMENTS
------------

"No special requirements".

INSTALLATION
------------
 
 * See:
   https://drupal.org/documentation/install/modules-themes/modules-7
   for further information.


Using Drush :
   * drush dl role_login_page
   * drush en role_login_page -y

Manually :
   * Unpack the module.
   * copy the module and place it in the sites/all/modules folder.
   * enable just like other module at /admin/modules page.

CONFIGURATION
-------------

* Configure user permissions in Administration » People » Permissions:

   - Administer Role Login Setings

     Users in roles with the "Administer Role Login Setings" permission will be
     able to add, edit, delete custom role login pages.

  1. Configure at Administer > Configuration > ROLE LOGIN SETTINGS > Role login settings list
  2. Click on add login page link.
  3. Then use the login path and verify the form and try to login.

MAINTAINERS
-----------

 * Nisith Ranjan Biswal (nisith)- https://www.drupal.org/user/1880840/

