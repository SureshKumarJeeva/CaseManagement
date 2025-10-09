<?php

namespace Drupal\paragraphs_ee;

use Drupal\Core\Render\Element;
use Drupal\paragraphs\Plugin\Field\FieldWidget\ParagraphsWidget;

/**
 * Helper class for ParagraphsEE.
 */
class ParagraphsEE {

  /**
   * Register features for paragraphs field widget.
   *
   * @param array $elements
   *   Render array for the field widget.
   * @param \Drupal\paragraphs\Plugin\Field\FieldWidget\ParagraphsWidget $widget
   *   Field widget object.
   */
  public static function registerWidgetFeatures(array &$elements, ParagraphsWidget $widget): void {
    $third_party_settings = $widget->getThirdPartySetting('paragraphs_ee', 'paragraphs_ee', []);
    if (isset($third_party_settings['drag_drop']) && ($third_party_settings['drag_drop'] === TRUE)) {
      $elements['#attached']['library'][] = 'paragraphs_ee/paragraphs_ee.drag_drop';
      $elements['#attached']['drupalSettings']['paragraphs_ee']['widgetTitle'] = $widget->getSetting('title');
      foreach (Element::children($elements) as $key) {
        $elements[$key]['top']['#attributes']['class'][] = 'drag-drop-buttons';
      }
    }
  }

  /**
   * Add gin accents to the widget.
   *
   * @param array $elements
   *   Render array for the field widget.
   */
  public static function addGinAccents(array &$elements): void {
    // Check if current theme is "gin" or a subtheme of "gin".
    $add_gin_accent_library = FALSE;
    $activeTheme = \Drupal::theme()->getActiveTheme();
    if ($activeTheme->getName() === 'gin') {
      $add_gin_accent_library = TRUE;
    }
    else {
      /** @var \Drupal\Core\Extension\ThemeExtensionList $theme_list */
      $theme_list = \Drupal::service('extension.list.theme');
      $ancestors = $theme_list->getBaseThemes($theme_list->getList(), $activeTheme->getName());
      $add_gin_accent_library = isset($ancestors['gin']);
    }

    if ($add_gin_accent_library) {
      $elements['add_more']['#attached']['library'][] = 'paragraphs_ee/paragraphs_ee.gin_accent';
    }
  }

}
