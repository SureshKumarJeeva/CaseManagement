<?php

/**
 * @file
 * Post update functions for Paragraphs Editor Enhancements.
 */

/**
 * Alter configuration schema of description in existing paragraph categories.
 */
function paragraphs_ee_post_update_alter_description_schema(): void {
  $config_factory = \Drupal::configFactory();
  $filter_format_default = filter_default_format();
  $names = $config_factory->listAll('paragraphs_ee.paragraphs_category.');

  foreach ($names as $name) {
    /** @var \Drupal\Core\Config\Config $config */
    $config = $config_factory->getEditable($name);
    $description = $config->get('description');
    if (!is_array($description)) {
      // Transform configuration to new structure.
      $description = [
        'value' => $description,
        'format' => $filter_format_default,
      ];
      $config->set('description', $description)
        ->save();
    }
  }
}
