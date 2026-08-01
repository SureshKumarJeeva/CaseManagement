<?php

namespace Drupal\associatedps_forms;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Single source of truth for "which fields exist and what are they called".
 *
 * Several unrelated screens need the same answer for the Job type:
 *  - the DocuSign template-editor token picker (associatedps_affidavit_template)
 *  - the Advanced Filter field dropdown on /jobs/filter
 *  - (next) the Available Tokens list on the Library email-template page
 *
 * All of them list fields and all of them must name them the way the user sees
 * them on the edit form rather than by machine name, because names like
 * Admin_Cover_Letter_Date are ambiguous once field_ is stripped. Keeping the
 * rules here means those lists cannot drift apart, and a fourth screen gets
 * them for free.
 *
 * Three rules live in this class:
 *  1. WHICH fields exist — the bundle's fields plus one level of
 *     reference/paragraph sub-fields, as "field_client__mail" paths.
 *  2. WHAT each is called — "<Tab>-<Section>-...-<Field>" read from the LIVE
 *     form display (config/sync is known to drift on this project).
 *  3. WHICH are omitted — anything not sitting inside a tab.
 *
 * What a caller does with a path afterwards is deliberately NOT here, because
 * each screen needs a different value from the same path:
 *   DocuSign      field_client__mail => Client.Mail
 *   Job filter    field_client__mail => field_client__mail (select value + query)
 *   Email tokens  field_client__mail => [node:field_client:mail]
 * A new screen is therefore a thin caller, not a copy of the walking logic.
 *
 * Defaults to node.job.default; pass different arguments to create() to point
 * it at another bundle, so a future "filter Clients" page needs no changes here.
 */
class FormDisplayFields {

  /**
   * Separator between tab / section / field label segments.
   */
  public const LABEL_SEPARATOR = '-';

  /**
   * Separator between a reference field and its sub-field inside a path.
   */
  public const PATH_SEPARATOR = '__';

  /**
   * Base fields allowed when traversing referenced entities one level deep.
   *
   * Referenced entities carry dozens of base fields (uid, created, langcode…)
   * that are noise on every screen, so only these are surfaced.
   */
  protected const BASE_FIELD_WHITELIST = [
    'node' => ['title'],
    'user' => ['name', 'mail'],
    'taxonomy_term' => ['name'],
    'paragraph' => [],
  ];

  protected EntityFieldManagerInterface $fieldManager;

  protected EntityTypeManagerInterface $entityTypeManager;

  protected string $entityTypeId;

  protected string $bundle;

  protected string $formMode;

  /**
   * Memoised per-request; the getters are called repeatedly while building a
   * form and each one walks every field definition on the bundle.
   *
   * Keyed by the $includeUntabbed flag ('tabbed' / 'untabbed') because the two
   * modes return different maps for the same display.
   */
  protected array $groupPathsCache = [];

  protected ?array $fieldPaths = NULL;

  protected array $subFieldCache = [];

  public function __construct(
    EntityFieldManagerInterface $field_manager,
    EntityTypeManagerInterface $entity_type_manager,
    string $entity_type_id = 'node',
    string $bundle = 'job',
    string $form_mode = 'default'
  ) {
    $this->fieldManager = $field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeId = $entity_type_id;
    $this->bundle = $bundle;
    $this->formMode = $form_mode;
  }

  /**
   * Convenience factory for callers that are not services (forms, controllers).
   *
   * Deliberately not registered in a services.yml: a constructor argument added
   * to a registered service needs a container rebuild to take effect, and this
   * project deploys by copying files. A plain `new` cannot fall out of sync.
   */
  public static function create(string $entity_type_id = 'node', string $bundle = 'job', string $form_mode = 'default'): self {
    return new self(
      \Drupal::service('entity_field.manager'),
      \Drupal::service('entity_type.manager'),
      $entity_type_id,
      $bundle,
      $form_mode
    );
  }

  /**
   * Every field path on the bundle, one level deep on references.
   *
   * e.g. ['title', 'field_client', 'field_client__mail', 'field_parties',
   *       'field_parties__field_first_name', ...]
   *
   * Unfiltered by tab placement — callers that want only the visible ones skip
   * the paths where buildLabel() returns NULL.
   */
  public function getFieldPaths(): array {
    if ($this->fieldPaths !== NULL) {
      return $this->fieldPaths;
    }

    $paths = ['title'];

    foreach ($this->getBundleFields() as $fieldName => $fieldDefinition) {
      if ($fieldDefinition->getFieldStorageDefinition()->isBaseField()) {
        continue;
      }

      $paths[] = $fieldName;

      if (!in_array($fieldDefinition->getType(), ['entity_reference', 'entity_reference_revisions'], TRUE)) {
        continue;
      }

      $targetType = $fieldDefinition->getSetting('target_type');
      $allowedBaseFields = self::BASE_FIELD_WHITELIST[$targetType] ?? [];

      foreach ($this->getTargetBundles($fieldDefinition) as $targetBundle) {
        foreach ($this->fieldManager->getFieldDefinitions($targetType, $targetBundle) as $subName => $subDefinition) {
          if ($subDefinition->getFieldStorageDefinition()->isBaseField()
            && !in_array($subName, $allowedBaseFields, TRUE)) {
            continue;
          }
          $paths[] = $fieldName . self::PATH_SEPARATOR . $subName;
        }
      }
    }

    $this->fieldPaths = array_values(array_unique($paths));
    return $this->fieldPaths;
  }

  /**
   * Readable label for a field path, or NULL when it must not be listed.
   *
   * "field_client_s_job_email"        => "Client-Job Details-Client's Job Email"
   * "field_parties__field_first_name" => "Parties-Parties-Side One Parties-First Name"
   *
   * The parent reference field's own label is included for sub-fields, because
   * sibling reference fields sharing a section otherwise collide — Side One
   * Parties, Side Two Parties and Other Parties all sit in Parties-Parties and
   * all have a First Name. It is dropped when a group above already says the
   * same thing, so field_client under the Client tab reads
   * "Client-Job Details-<sub>" instead of repeating "Client".
   *
   * By default NULL means the field is not inside a tab — the "hidden" group,
   * its Reporting subgroup, group_client_detail, or the disabled region. Every
   * Job-form caller wants those omitted, so that rule is the default.
   *
   * Pass $includeUntabbed = TRUE for bundles laid out WITHOUT tabs (e.g. the
   * online_instruction node form, which uses only fieldsets): fields then fall
   * back to their group/fieldset chain, or to the plain field label when they
   * sit in no group at all. This keeps the rule here rather than teaching each
   * caller its own version — only the flag differs per screen.
   */
  public function buildLabel(string $path, bool $includeUntabbed = FALSE): ?string {
    $groupPaths = $this->getGroupPaths($includeUntabbed);
    $bundleFields = $this->getBundleFields();

    $parts = explode(self::PATH_SEPARATOR, $path, 2);
    $root = $parts[0];

    if (!isset($bundleFields[$root])) {
      return NULL;
    }
    if (isset($groupPaths[$root])) {
      $segments = $groupPaths[$root];
    }
    elseif ($includeUntabbed) {
      // Field belongs to no group at all — label it by itself.
      $segments = [];
    }
    else {
      return NULL;
    }

    if (count($parts) === 1) {
      $segments[] = (string) $bundleFields[$root]->getLabel();
    }
    else {
      $subLabels = $this->getReferencedFieldLabels($bundleFields[$root]);
      if (!isset($subLabels[$parts[1]])) {
        return NULL;
      }
      $parentLabel = (string) $bundleFields[$root]->getLabel();
      if (!in_array($parentLabel, $segments, TRUE)) {
        $segments[] = $parentLabel;
      }
      $segments[] = $subLabels[$parts[1]];
    }

    return implode(self::LABEL_SEPARATOR, $segments);
  }

  /**
   * The field definition a path points at, or NULL if it resolves to nothing.
   *
   * Callers that build widgets need a sub-field's own type / settings /
   * allowed values, which live on the REFERENCED entity, not on this bundle.
   */
  public function getFieldDefinition(string $path): ?FieldDefinitionInterface {
    $bundleFields = $this->getBundleFields();

    $parts = explode(self::PATH_SEPARATOR, $path, 2);
    $root = $parts[0];

    if (!isset($bundleFields[$root])) {
      return NULL;
    }
    if (count($parts) === 1) {
      return $bundleFields[$root];
    }

    $targetType = $bundleFields[$root]->getSetting('target_type');
    if (empty($targetType)) {
      return NULL;
    }
    foreach ($this->getTargetBundles($bundleFields[$root]) as $targetBundle) {
      $subFields = $this->fieldManager->getFieldDefinitions($targetType, $targetBundle);
      if (isset($subFields[$parts[1]])) {
        return $subFields[$parts[1]];
      }
    }

    return NULL;
  }

  /**
   * Tab/section ancestry of each field, from the LIVE form display.
   *
   * Reads the field_group third-party settings on the display (what the
   * "Manage form display" page edits) and returns, for every field placed
   * inside a tab, its ordered group labels:
   *   field_client_s_job_email      => ['Client', 'Job Details']
   *   field_attendance_date_service => ['Attendance Note',
   *                                     'Attendance Note Details',
   *                                     'Attendance Service Details']
   *
   * A missing key means "not inside a tab" (or, with $includeUntabbed, "not
   * inside any group").
   *
   * With $includeUntabbed = TRUE the ancestry is returned even when it never
   * reaches a tab, so a fieldset-only layout (e.g. online_instruction) still
   * yields chains. Fields in no group are simply absent from the map; the
   * caller (buildLabel) treats that as "plain label".
   *
   * Read live rather than from config/sync because the exported config is
   * known to drift from the running site.
   */
  public function getGroupPaths(bool $includeUntabbed = FALSE): array {
    $cacheKey = $includeUntabbed ? 'untabbed' : 'tabbed';
    if (isset($this->groupPathsCache[$cacheKey])) {
      return $this->groupPathsCache[$cacheKey];
    }
    $result = [];

    $displayId = $this->entityTypeId . '.' . $this->bundle . '.' . $this->formMode;

    try {
      $display = $this->entityTypeManager
        ->getStorage('entity_form_display')
        ->load($displayId);
    }
    catch (\Exception $e) {
      \Drupal::logger('associatedps_forms')->warning('Could not load @id form display: @msg', [
        '@id' => $displayId,
        '@msg' => $e->getMessage(),
      ]);
      $this->groupPathsCache[$cacheKey] = $result;
      return $result;
    }

    if (!$display) {
      $this->groupPathsCache[$cacheKey] = $result;
      return $result;
    }

    $groups = $display->getThirdPartySettings('field_group');
    if (empty($groups)) {
      $this->groupPathsCache[$cacheKey] = $result;
      return $result;
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

    foreach ($owner as $fieldName => $groupName) {
      // Skip fields sitting in the form's disabled region.
      if (!$display->getComponent($fieldName)) {
        continue;
      }
      $chain = $this->getGroupLabelChain($groups, $groupName, $includeUntabbed);
      if ($chain !== NULL) {
        $result[$fieldName] = $chain;
      }
    }

    $this->groupPathsCache[$cacheKey] = $result;
    return $result;
  }

  /**
   * Field definitions of the bundle this instance describes.
   */
  protected function getBundleFields(): array {
    return $this->fieldManager->getFieldDefinitions($this->entityTypeId, $this->bundle);
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
      foreach ($handlerSettings['target_bundles_drag_drop'] as $dragDropBundle => $info) {
        if (!empty($info['enabled'])) {
          $targetBundles[$dragDropBundle] = $dragDropBundle;
        }
      }
    }

    return $targetBundles;
  }

  /**
   * Sub-field labels of a reference field, keyed by sub-field machine name.
   *
   * A reference field may allow several bundles; where the same sub-field
   * name appears in more than one, the first bundle's label wins.
   */
  protected function getReferencedFieldLabels(FieldDefinitionInterface $fieldDefinition): array {
    $cacheKey = $fieldDefinition->getName();
    if (isset($this->subFieldCache[$cacheKey])) {
      return $this->subFieldCache[$cacheKey];
    }

    $labels = [];
    $targetType = $fieldDefinition->getSetting('target_type');
    if (!empty($targetType)) {
      foreach ($this->getTargetBundles($fieldDefinition) as $targetBundle) {
        foreach ($this->fieldManager->getFieldDefinitions($targetType, $targetBundle) as $subName => $subDefinition) {
          if (!isset($labels[$subName])) {
            $labels[$subName] = (string) $subDefinition->getLabel();
          }
        }
      }
    }

    $this->subFieldCache[$cacheKey] = $labels;
    return $labels;
  }

  /**
   * Walk a group up to its tab, collecting labels outermost-first.
   *
   * Returns NULL when the ancestry never reaches a tab, UNLESS
   * $includeUntabbed is TRUE — then the collected chain (fieldsets and all) is
   * returned regardless, for bundles that have no tabs. The caller treats NULL
   * as "do not list this field".
   */
  protected function getGroupLabelChain(array $groups, string $groupName, bool $includeUntabbed = FALSE): ?array {
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

    if ($hasTab) {
      return array_reverse($chain);
    }
    return $includeUntabbed ? array_reverse($chain) : NULL;
  }

}
