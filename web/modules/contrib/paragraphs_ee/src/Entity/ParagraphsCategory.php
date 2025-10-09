<?php

namespace Drupal\paragraphs_ee\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\paragraphs_ee\ParagraphsCategoryInterface;

/**
 * Defines the Example entity.
 *
 * @ConfigEntityType(
 *   id = "paragraphs_category",
 *   label = @Translation("Paragraphs category"),
 *   handlers = {
 *     "list_builder" = "Drupal\paragraphs_ee\Controller\ParagraphsCategoryListBuilder",
 *     "form" = {
 *       "add" = "Drupal\paragraphs_ee\Form\ParagraphsCategoryForm",
 *       "edit" = "Drupal\paragraphs_ee\Form\ParagraphsCategoryForm",
 *       "delete" = "Drupal\paragraphs_ee\Form\ParagraphsCategoryDeleteForm",
 *     }
 *   },
 *   config_prefix = "paragraphs_category",
 *   admin_permission = "administer paragraphs categories",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "weight" = "weight"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "description",
 *     "weight"
 *   },
 *   links = {
 *     "edit-form" = "/admin/structure/paragraphs_category/{paragraphs_category}",
 *     "delete-form" = "/admin/structure/paragraphs_category/{paragraphs_category}/delete",
 *   }
 * )
 */
class ParagraphsCategory extends ConfigEntityBase implements ParagraphsCategoryInterface {

  /**
   * The category ID.
   *
   * @var string
   */
  public $id;

  /**
   * The category label.
   *
   * @var string
   */
  public $label;

  /**
   * The category description.
   *
   * @var array<string, string>
   */
  public $description;

  /**
   * The category weight.
   *
   * @var int
   */
  public $weight;

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    if (!is_array($this->description)) {
      // Rewrite to new structure.
      $this->description = [
        // @phpstan-ignore-next-line
        'value' => $this->description ?? '',
        'format' => filter_default_format(),
      ];
    }
    return check_markup($this->description['value'], $this->getDescriptionFormat());
  }

  /**
   * {@inheritdoc}
   */
  public function getDescriptionFormat(): ?string {
    return $this->description['format'] ?? filter_default_format();
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight() {
    /** @var int $weight */
    $weight = $this->get('weight') ?? 0;
    return $weight;
  }

}
