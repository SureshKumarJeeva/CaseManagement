<?php

namespace Drupal\paragraphs_ee;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for Paragraphs category entities.
 */
interface ParagraphsCategoryInterface extends ConfigEntityInterface {

  /**
   * Get the formatted description of the Paragraphs category.
   *
   * @return string
   *   The formatted category description.
   */
  public function getDescription();

  /**
   * Get the description format of the Paragraphs category.
   *
   * @return string|null
   *   The category description format.
   */
  public function getDescriptionFormat();

  /**
   * Get the weight of the Paragraphs category.
   *
   * @return int
   *   The category weight.
   */
  public function getWeight();

}
