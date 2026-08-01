<?php

namespace Drupal\associatedps_affidavit_template\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
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

  protected EntityTypeManagerInterface $entityTypeManager;

  /** Form display whose tab/section tree names the tokens for template authors. */
  protected const JOB_FORM_DISPLAY = 'node.job.default';

  /** Separator between tab / section / field label segments. */
  protected const LABEL_SEPARATOR = '-';

  /** Base fields allowed when traversing referenced entities one level deep. */
  protected const BASE_FIELD_WHITELIST = [
    'node' => ['title'],
    'user' => ['name', 'mail'],
    'taxonomy_term' => ['name'],
    'paragraph' => [],
  ];

  public function __construct(EntityFieldManagerInterface $field_manager, EntityTypeManagerInterface $entity_type_manager) {
    $this->fieldManager = $field_manager;
    $this->entityTypeManager = $entity_type_manager;
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
      $targetBundles = $this->getTargetBundles($fieldDefinition);

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
   * Bundles an entity-reference field is allowed to point at.
   *
   * Plain reference fields store this in handler_settings.target_bundles;
   * Paragraphs stores it in target_bundles_drag_drop with a per-type
   * "enabled" flag instead. Callers use the result to decide whose field
   * definitions to read when traversing one level deep.
   */
  protected function getTargetBundles(FieldDefinitionInterface $fieldDefinition): array {
    $handlerSettings = $fieldDefinition->getSetting('handler_settings');
    $targetBundles = $handlerSettings['target_bundles'] ?? [];

    if (empty($targetBundles) && !empty($handlerSettings['target_bundles_drag_drop'])) {
      foreach ($handlerSettings['target_bundles_drag_drop'] as $bundle => $info) {
        if (!empty($info['enabled'])) {
          $targetBundles[$bundle] = $bundle;
        }
      }
    }

    return $targetBundles;
  }

  /**
   * Token options for the template editor picker: readable label + raw value.
   *
   * The label is the field's location on the Job "Manage form display" page —
   * "<Tab>-<Section>-...-<Field>", e.g.
   * "Client-Job Details-Client's Job Email". Machine-derived names such as
   * Admin_Cover_Letter_Date were ambiguous because several fields collapse to
   * near-identical names once field_ is stripped.
   *
   * The VALUE is deliberately unchanged — it is still formatVariableName()
   * output, which is what the author pastes into a tab's Data Label and what
   * tokensToDocuSignTabs() matches at generate time. Only the display moves.
   *
   * Fields not inside a tab are omitted (the "hidden" group, its Reporting
   * subgroup, group_client_detail and anything in the disabled region). This
   * filters the PICKER only; resolveTokensForJob() still resolves every path,
   * so templates already using a hidden field's token keep working.
   *
   * @return array List of ['label' => ..., 'value' => ...].
   */
  public function getJobVariableOptions(): array {
    $groupPaths = $this->getFormDisplayGroupPaths();

    // No field_group data at all — fall back to the flat canonical names so
    // the picker is never empty.
    if (empty($groupPaths)) {
      return array_map(
        fn($name) => ['label' => $name, 'value' => $name],
        $this->getJobVariableNames()
      );
    }

    $jobFields = $this->fieldManager->getFieldDefinitions('node', 'job');
    $subLabels = [];
    $options = [];

    foreach ($this->getJobFieldPaths() as $path) {
      $parts = explode('__', $path);
      $root = $parts[0];

      // Root field must be placed inside a tab on the form display.
      if (!isset($groupPaths[$root], $jobFields[$root])) {
        continue;
      }
      $segments = $groupPaths[$root];

      if (count($parts) === 1) {
        $segments[] = (string) $jobFields[$root]->getLabel();
      }
      else {
        // Reference sub-field: the PARENT field's tab/section path, then the
        // parent field's own label, then the sub-field's label from the
        // referenced entity. The parent label distinguishes sibling reference
        // fields sharing a section (Side One Parties / Side Two Parties /
        // Other Parties all sit in Parties-Parties and all have a First Name).
        // It is dropped when a group above already says the same thing, so
        // field_client under the Client tab reads "Client-Job Details-<sub>"
        // rather than repeating "Client".
        if (!isset($subLabels[$root])) {
          $subLabels[$root] = $this->getReferencedFieldLabels($jobFields[$root]);
        }
        if (!isset($subLabels[$root][$parts[1]])) {
          continue;
        }
        $parentLabel = (string) $jobFields[$root]->getLabel();
        if (!in_array($parentLabel, $segments, TRUE)) {
          $segments[] = $parentLabel;
        }
        $segments[] = $subLabels[$root][$parts[1]];
      }

      $options[] = [
        'label' => implode(self::LABEL_SEPARATOR, $segments),
        'value' => $this->formatVariableName($path),
      ];
    }

    // Alphabetical on the new label groups every tab's fields together.
    usort($options, fn($a, $b) => strcasecmp($a['label'], $b['label']));

    return $options;
  }

  /**
   * Sub-field labels of a reference field, keyed by sub-field machine name.
   *
   * A reference field may allow several bundles; where the same sub-field
   * name appears in more than one, the first bundle's label wins.
   */
  protected function getReferencedFieldLabels(FieldDefinitionInterface $fieldDefinition): array {
    $targetType = $fieldDefinition->getSetting('target_type');
    if (empty($targetType)) {
      return [];
    }

    $labels = [];
    foreach ($this->getTargetBundles($fieldDefinition) as $bundle) {
      foreach ($this->fieldManager->getFieldDefinitions($targetType, $bundle) as $subName => $subDefinition) {
        if (!isset($labels[$subName])) {
          $labels[$subName] = (string) $subDefinition->getLabel();
        }
      }
    }

    return $labels;
  }

  /**
   * Tab/section ancestry of each Job field, from the LIVE form display.
   *
   * Reads the field_group third-party settings on node.job.default (what the
   * "Manage form display" page edits) and returns, for every field placed
   * inside a tab, its ordered group labels:
   *   field_client_s_job_email      => ['Client', 'Job Details']
   *   field_attendance_date_service => ['Attendance Note',
   *                                     'Attendance Note Details',
   *                                     'Attendance Service Details']
   *
   * A missing key means "not inside a tab" — omit the token. That covers the
   * "hidden" group, group_client_detail, and the disabled region.
   *
   * Read live rather than from config/sync because the exported config is
   * known to drift from the running site.
   */
  protected function getFormDisplayGroupPaths(): array {
    try {
      $display = $this->entityTypeManager
        ->getStorage('entity_form_display')
        ->load(self::JOB_FORM_DISPLAY);
    }
    catch (\Exception $e) {
      \Drupal::logger('docusign')->warning('Could not load @id form display: @msg', [
        '@id' => self::JOB_FORM_DISPLAY,
        '@msg' => $e->getMessage(),
      ]);
      return [];
    }

    if (!$display) {
      return [];
    }

    $groups = $display->getThirdPartySettings('field_group');
    if (empty($groups)) {
      return [];
    }

    // Map each field to its owning group. A child naming another group is
    // structure, not a field.
    $owner = [];
    foreach ($groups as $groupName => $group) {
      foreach (($group['children'] ?? []) as $child) {
        if (!isset($groups[$child])) {
          $owner[$child] = $groupName;
        }
      }
    }

    $paths = [];
    foreach ($owner as $fieldName => $groupName) {
      // Skip fields sitting in the form's disabled region.
      if (!$display->getComponent($fieldName)) {
        continue;
      }
      $chain = $this->getGroupLabelChain($groups, $groupName);
      if ($chain !== NULL) {
        $paths[$fieldName] = $chain;
      }
    }

    return $paths;
  }

  /**
   * Walk a group up to its tab, collecting labels outermost-first.
   *
   * Returns NULL when the ancestry never reaches a tab — the caller treats
   * that as "do not offer this token".
   */
  protected function getGroupLabelChain(array $groups, string $groupName): ?array {
    $chain = [];
    $hasTab = FALSE;
    $guard = 0;

    while ($groupName !== '' && isset($groups[$groupName]) && $guard++ < 20) {
      $group = $groups[$groupName];
      $formatType = $group['format_type'] ?? '';

      // The outer "tabs" element only holds the tabs; it is not itself a tab
      // and never appears in the label.
      if ($formatType === 'tabs') {
        break;
      }
      if ($formatType === 'tab') {
        $hasTab = TRUE;
      }

      $chain[] = (string) ($group['label'] ?? $groupName);
      $groupName = (string) ($group['parent_name'] ?? '');
    }

    return $hasTab ? array_reverse($chain) : NULL;
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
