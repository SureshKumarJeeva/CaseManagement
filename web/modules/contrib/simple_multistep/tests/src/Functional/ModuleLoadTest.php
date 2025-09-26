<?php

namespace Drupal\Tests\simple_multistep\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * The Simple Multistep module load tests.
 *
 * @group simple_multistep
 */
class ModuleLoadTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stable9';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'block',
    'field_group',
    'simple_multistep',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalPlaceBlock('page_title_block');
  }

  /**
   * Test the homepage after enabling the module Simple Multistep.
   */
  public function testHomepage() {
    // Check the homepage.
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);

    // Minimal homepage title check.
    $this->assertSession()->pageTextContains('Log in');
  }

  /**
   * Test the Simple Multistep module unistall.
   */
  public function testModuleUninstall() {
    // Create a new admin user.
    $admin_user = $this->drupalCreateUser([
      'access administration pages',
      'administer site configuration',
      'administer modules',
    ]);
    // Uninstall the module.
    $this->drupalLogin($admin_user);
    $this->drupalGet('/admin/modules/uninstall');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Simple multistep');
    $this->submitForm(['uninstall[simple_multistep]' => TRUE], 'Uninstall');
    $this->submitForm([], 'Uninstall');
    $this->assertSession()->pageTextContains('The selected modules have been uninstalled.');
    $this->assertSession()->pageTextNotContains('Simple multistep');

    // Visit the frontpage.
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Test the Simple Multistep module reinstalling after being uninstalled.
   */
  public function testReinstallAfterUninstall() {
    // Create a new admin user.
    $admin_user = $this->drupalCreateUser([
      'access administration pages',
      'administer site configuration',
      'administer modules',
    ]);
    // Uninstall the module.
    $this->drupalLogin($admin_user);

    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    // Uninstall the Simple Multistep module.
    $this->container->get('module_installer')->uninstall(['simple_multistep'], FALSE);

    // Install the Simple Multistep module.
    $this->drupalGet('/admin/modules');
    $page->checkField('modules[simple_multistep][enable]');
    $page->pressButton('Install');
    $assert_session->pageTextNotContains('Unable to install Simple multistep');
    $assert_session->pageTextContains('Module Simple multistep has been enabled');
  }

}
