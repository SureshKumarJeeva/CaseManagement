<?php

namespace Drupal\Tests\simple_multistep\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;

/**
 * The Simple Multistep module "Form step" creation tests.
 *
 * @group simple_multistep
 */
class CreateFormStepGroupTest extends BrowserTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stable9';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field_ui',
    'field_group',
    'simple_multistep',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a new admin user.
    $admin_user = $this->drupalCreateUser([
      'access content',
      'administer content types',
      'administer node fields',
      'administer node form display',
      'administer node display',
      'bypass node access',
    ]);
    // Login the user.
    $this->drupalLogin($admin_user);
    // Ensure that the "article" node type exists.
    $this->createContentType(['type' => 'article', 'name' => 'Article']);
  }

  /**
   * Test the "Form step" group type creation.
   */
  public function testAddFormStepGroup() {
    $page = $this->getSession()->getPage();
    // Visit the article form add group page.
    $this->drupalGet('admin/structure/types/manage/article/form-display/add-group');
    $this->assertSession()->statusCodeEquals(200);

    // Setup the group of the type "form_step" (Form step).
    $group_label = 'Test Step';
    $machine_name = str_replace(' ', '_', $group_label);
    $group_name = 'group_' . mb_strtolower($machine_name);

    // Fill in the group name, type and label.
    $page->fillField('group_formatter', 'form_step');
    $page->fillField('label', $group_label);
    $page->fillField('group_name', mb_strtolower($machine_name));

    $page->hasButton('Save and continue');
    $page->pressButton('Save and continue');

    // Fill in the rest group data.
    $page->fillField('format_settings[show_step_title]', TRUE);
    $page->fillField('format_settings[back_button_show]', TRUE);
    $page->fillField('format_settings[back_button_text]', 'Back');
    $page->fillField('format_settings[next_button_text]', 'Next');
    $page->fillField('format_settings[step_description]', '');
    $page->fillField('format_settings[step_help]', '');

    $page->hasButton('Create group');
    $page->pressButton('Create group');

    $this->assertSession()->statusCodeEquals(200);

    // Test if the group is in the $groups array.
    $loaded_group = field_group_load_field_group($group_name, 'node', 'article', 'form', 'default');
    $this->assertNotNull($loaded_group, 'Group was loaded');
  }

}
