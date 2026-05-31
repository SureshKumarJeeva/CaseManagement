<?php

namespace Drupal\associatedps_affidavit_template\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Maps Job content-type fields to DocuSign tab labels.
 *
 * Mirrors PandaDocTokenMapper so the same canonical variable vocabulary
 * (e.g. Client.Mail, Para_Address.Selected_Address) is shared across both
 * integrations. Template authors who place DocuSign Tabs label them using
 * these same names; tokensToDocuSignTabs() converts resolved [name,value]
 * pairs into the textTabs/dateTabs shape expected by the Envelopes API.
 */
class DocuSignTokenMapper {

  protected EntityFieldManagerInterface $fieldManager;

  /** Base fields allowed when traversing referenced entities one level deep. */
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
   * Convert a raw field-path machine name to the canonical variable name.
   *
   * e.g. field_client__mail → Client.Mail
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
   * Build raw field paths from Job content type (one level deep on refs).
   */
  public function getJobFieldPaths(): array {
    $paths = ['title'];
    $jobFields = $this->fieldManager->getFieldDefinitions('node', 'job');

    foreach ($jobFields as $fieldName => $fieldDefinition) {
      if ($fieldDefinition->getFieldStorageDefinition()->isBaseField()) {
        continue;
      }

      $paths[] = $fieldName;

      $fieldType = $fieldDefinition->getType();
      if (!in_array($fieldType, ['entity_reference', 'entity_reference_revisions'])) {
        continue;
      }

      $targetType = $fieldDefinition->getSetting('target_type');
      $handlerSettings = $fieldDefinition->getSetting('handler_settings');
      $targetBundles = $handlerSettings['target_bundles'] ?? [];

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
   * Canonical variable names suitable for showing to template authors.
   *
   * e.g. ['Title', 'Client.Mail', 'Hearing_Date', ...]
   */
  public function getJobVariableNames(): array {
    $paths = $this->getJobFieldPaths();
    return array_map(fn($path) => $this->formatVariableName($path), $paths);
  }

  /**
   * Drupal token strings in [node:field_name] notation.
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
   * Resolve all token values from a Job node.
   *
   * @return array List of ['name' => 'Client.Mail', 'value' => 'a@b.com'].
   */
  public function resolveTokensForJob(NodeInterface $jobNode): array {
    $paths = $this->getJobFieldPaths();
    $apiNames = array_map([$this, 'formatVariableName'], $paths);
    $tokens = $this->getJobTokens();

    if (empty($tokens)) {
      return [];
    }

    $markerPrefix = '%%DOCUSIGN_MARKER_';
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

    if (!function_exists('_associatedps_pdf_templates_replace_value')) {
      \Drupal::logger('docusign')->warning('Token replacer function not found.');
      return [];
    }

    $resolved = _associatedps_pdf_templates_replace_value($elements, $jobNode, $templateArray);

    $result = [];
    foreach ($tokens as $idx => $drupalToken) {
      $startMarker = $markerPrefix . $idx . '%%';
      $startPos = strpos($resolved['body'], $startMarker);
      $resolvedValue = '';

      if ($startPos !== FALSE) {
        $startPos += strlen($startMarker);
        $endPos = strpos($resolved['body'], $markerSuffix, $startPos);
        if ($endPos !== FALSE) {
          $resolvedValue = substr($resolved['body'], $startPos, $endPos - $startPos);
        }
      }

      $resolvedValue = trim(strip_tags($resolvedValue));
      $result[] = [
        'name' => $apiNames[$idx],
        'value' => $resolvedValue,
      ];
    }

    return $result;
  }

  /**
   * Convert resolved [name, value] pairs into DocuSign tabs shape.
   *
   * Returns ['textTabs' => [{tabLabel, value}, ...], 'dateTabs' => [...]].
   * Date detection is heuristic: value matches YYYY-MM-DD or similar →
   * dateTab; otherwise textTab. Template authors choose the actual tab type
   * when placing tabs in the editor; we match by tabLabel either way.
   */
  public function tokensToDocuSignTabs(array $resolved): array {
    $textTabs = [];
    $dateTabs = [];
    foreach ($resolved as $token) {
      if (empty($token['name'])) {
        continue;
      }
      $entry = [
        'tabLabel' => $token['name'],
        'value' => (string) ($token['value'] ?? ''),
      ];
      if ($this->looksLikeDate($entry['value'])) {
        $dateTabs[] = $entry;
      }
      else {
        $textTabs[] = $entry;
      }
    }
    return [
      'textTabs' => $textTabs,
      'dateTabs' => $dateTabs,
    ];
  }

  protected function looksLikeDate(string $value): bool {
    return $value !== '' && (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', $value);
  }

}
