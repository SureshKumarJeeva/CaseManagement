<?php

namespace Drupal\associatedps_affidavit_template\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\node\NodeInterface;

class PandaDocTokenMapper {

  protected EntityFieldManagerInterface $fieldManager;

  /**
   * Allowed base fields per entity type for sub-field traversal.
   */
  protected const BASE_FIELD_WHITELIST = [
    'node' => ['title'],
    'user' => ['name', 'mail'],
    'taxonomy_term' => ['name'],
    'paragraph' => [],
  ];

  public function __construct(EntityFieldManagerInterface $field_manager) {
    $this->fieldManager = $field_manager;
  }

  /**
   * Format a raw machine name into a user-friendly PandaDoc variable name.
   *
   * Removes 'field_' prefix, capitalizes first letter of each word,
   * uses '.' as connector for sub-fields.
   *
   * e.g. field_para_address__field_selected_address → Para_Address.Selected_Address
   *      title → Title
   *      field_client__mail → Client.Mail
   */
  public function formatVariableName(string $rawPath): string {
    $parts = explode('__', $rawPath);
    $formatted = [];
    foreach ($parts as $part) {
      $clean = preg_replace('/^field_/', '', $part);
      $clean = implode('_', array_map('ucfirst', explode('_', $clean)));
      $formatted[] = $clean;
    }
    return implode('.', $formatted);
  }

  /**
   * Dynamically build the raw field paths from Job content type.
   *
   * Returns machine-name paths like 'field_client__field_email'.
   * Used internally for Drupal token resolution.
   *
   * @return array List of raw field paths.
   */
  public function getJobFieldPaths(): array {
    $paths = ['title'];
    $jobFields = $this->fieldManager->getFieldDefinitions('node', 'job');

    foreach ($jobFields as $fieldName => $fieldDefinition) {
      if ($fieldDefinition->getFieldStorageDefinition()->isBaseField()) {
        continue;
      }

      $paths[] = $fieldName;

      // Traverse entity reference fields one level deep.
      $fieldType = $fieldDefinition->getType();
      if (!in_array($fieldType, ['entity_reference', 'entity_reference_revisions'])) {
        continue;
      }

      $targetType = $fieldDefinition->getSetting('target_type');
      $handlerSettings = $fieldDefinition->getSetting('handler_settings');
      $targetBundles = $handlerSettings['target_bundles'] ?? [];

      // For entity_reference_revisions (paragraphs), target_bundles may be
      // nested under 'target_bundles_drag_drop'.
      if (empty($targetBundles) && !empty($handlerSettings['target_bundles_drag_drop'])) {
        foreach ($handlerSettings['target_bundles_drag_drop'] as $bundle => $info) {
          if (!empty($info['enabled'])) {
            $targetBundles[$bundle] = $bundle;
          }
        }
      }

      $allowedBaseFields = self::BASE_FIELD_WHITELIST[$targetType] ?? [];

      foreach ($targetBundles as $bundle) {
        $targetFields = $this->fieldManager->getFieldDefinitions($targetType, $bundle);
        foreach ($targetFields as $subFieldName => $subFieldDef) {
          if ($subFieldDef->getFieldStorageDefinition()->isBaseField()) {
            if (in_array($subFieldName, $allowedBaseFields)) {
              $paths[] = $fieldName . '__' . $subFieldName;
            }
            continue;
          }
          $paths[] = $fieldName . '__' . $subFieldName;
        }
      }
    }

    return array_unique($paths);
  }

  /**
   * Get user-friendly PandaDoc variable names for the editor (with square brackets).
   *
   * These are what the user copies and pastes into the PandaDoc template.
   * e.g. [Para_Address.Selected_Address]
   *
   * @return array List of formatted variable names with brackets.
   */
  public function getJobVariableNames(): array {
    $paths = $this->getJobFieldPaths();
    return array_map(function ($path) {
      return $this->formatVariableName($path);
    }, $paths);
  }

  /**
   * Build the list of Drupal tokens from Job fields.
   *
   * Returns tokens in [node:field_name] format, matching what the
   * token replacer expects.
   *
   * @return array List of Drupal token strings.
   */
  public function getJobTokens(): array {
    $paths = $this->getJobFieldPaths();
    $tokens = [];
    foreach ($paths as $path) {
      $fieldPath = str_replace('__', ':', $path);
      $tokens[] = '[node:' . $fieldPath . ']';
    }
    return $tokens;
  }

  /**
   * Resolve all token values from a Job node and return them in PandaDoc
   * format: [{name: "...", value: "..."}, ...].
   *
   * Uses the dynamically built Job field list instead of field_available_tokens.
   *
   * @param \Drupal\node\NodeInterface $jobNode The Job node with data.
   *
   * @return array Array of ['name' => pandadoc_name, 'value' => resolved_value].
   */
  public function resolveTokensForJob(NodeInterface $jobNode): array {
    $paths = $this->getJobFieldPaths();
    $apiNames = array_map([$this, 'formatVariableName'], $paths);
    $tokens = $this->getJobTokens();

    if (empty($tokens)) {
      return [];
    }

    // Build a synthetic template body where each token is wrapped with
    // unique markers so we can extract the resolved value after replacement.
    $markerPrefix = '%%PANDADOC_MARKER_';
    $markerSuffix = '_END%%';
    $syntheticBody = '';
    foreach ($tokens as $idx => $token) {
      $syntheticBody .= $markerPrefix . $idx . '%%' . $token . $markerSuffix;
    }

    $templateArray = [
      'body' => $syntheticBody,
      'tokens' => $tokens,
      'subject' => '',
    ];

    // Build the field element map for Job content type.
    $elements = [];
    $jobFields = $this->fieldManager->getFieldDefinitions('node', 'job');
    foreach ($jobFields as $fieldName => $fieldDefinition) {
      if (!empty($fieldDefinition->getTargetBundle())) {
        $elements[$fieldName] = [
          'type' => $fieldDefinition->getType(),
          'label' => $fieldDefinition->getLabel(),
          'target_type' => $fieldDefinition->getSetting('target_type') ?? '',
        ];
      }
    }

    // Call the existing token replacer if available.
    if (!function_exists('_associatedps_pdf_templates_replace_value')) {
      \Drupal::logger('pandadoc')->warning('Token replacer function not found.');
      return [];
    }

    $resolved = _associatedps_pdf_templates_replace_value($elements, $jobNode, $templateArray);

    // Extract resolved values from the marker-wrapped body.
    $result = [];
    foreach ($tokens as $idx => $drupalToken) {
      $startMarker = $markerPrefix . $idx . '%%';
      $endMarker = $markerSuffix;
      $startPos = strpos($resolved['body'], $startMarker);
      $resolvedValue = '';

      if ($startPos !== FALSE) {
        $startPos += strlen($startMarker);
        $endPos = strpos($resolved['body'], $endMarker, $startPos);
        if ($endPos !== FALSE) {
          $resolvedValue = substr($resolved['body'], $startPos, $endPos - $startPos);
        }
      }

      $resolvedValue = strip_tags($resolvedValue);
      $resolvedValue = trim($resolvedValue);

      $result[] = [
        'name' => $apiNames[$idx],
        'value' => $resolvedValue,
      ];
    }

    return $result;
  }

}
