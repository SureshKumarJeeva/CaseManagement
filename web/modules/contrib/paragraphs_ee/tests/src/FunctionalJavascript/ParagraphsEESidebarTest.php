<?php

namespace Drupal\Tests\paragraphs_ee\FunctionalJavascript;

/**
 * Tests the dialog sidebar.
 *
 * @group paragraphs_ee
 */
class ParagraphsEESidebarTest extends ParagraphsEEJavascriptTestBase {

  /**
   * Tests disabling the sidebar.
   */
  public function testDisableSidebar() {
    // Create paragraph types and content types with required configuration for
    // testing of add in between feature.
    $content_type = 'test_modal_sidebar';

    // Create content type with test paragraphs.
    $this->createTestConfiguration(
      content_type: $content_type,
      num_of_test_paragraphs: 5,
    );

    $session = $this->getSession();
    $page = $session->getPage();
    $driver = $session->getDriver();
    /** @var \Drupal\FunctionalJavascriptTests\WebDriverWebAssert $assertSession */
    $assertSession = $this->assertSession();

    // Set option to disable sidebar.
    $this->drupalGet("admin/structure/types/manage/$content_type/form-display");
    $page->pressButton('field_paragraphs_settings_edit');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $page->checkField('fields[field_paragraphs][settings_edit_form][third_party_settings][paragraphs_ee][paragraphs_ee][sidebar_disabled]');

    $this->submitForm([], 'Update');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->submitForm([], 'Save');

    // Check that add in between functionality is used.
    $this->drupalGet("node/add/$content_type");

    $assertSession->waitForElementVisible('css', '.paragraphs-features__add-in-between__button');
    $this->assertEquals(TRUE, $driver->isVisible('//button[contains(concat(" ", normalize-space(@class), " "), " paragraphs-features__add-in-between__button ")]'), 'Add in between button should be visible.');

    // Open dialog and add a paragraph.
    $dialog = $this->openDialog('field_paragraphs');

    $sidebar = $dialog->find('css', '.paragraphs-ee-category-list-wrapper');
    $this->assertNull($sidebar, 'The dialog sidebar should not be visible.');
  }

}
