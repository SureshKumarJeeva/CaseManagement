<?php

namespace Drupal\associatedps_forms\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the Jobs advanced filter page.
 */
class JobFilterController extends ControllerBase {

  /**
   * Renders the filter form and filtered results.
   */
  public function page(Request $request): array {
    $build = [];

    $build['#attached']['library'][] = 'associatedps_forms/job_filter';

    // Render the filter form.
    $build['filter_form'] = \Drupal::formBuilder()->getForm('Drupal\associatedps_forms\Form\JobFilterForm');

    // Read filter parameters from query string.
    $filter_fields = $request->query->all('filter_field');
    $filter_values = $request->query->all('filter_value');
    $per_page = (int) $request->query->get('per_page', 10);
    $allowed = [10, 20, 30, 40, 50];
    $per_page = in_array($per_page, $allowed) ? $per_page : 10;

    // Resolve the server-side sort from the clicked column header (tablesort).
    $sort = $this->getSortContext($request);

    // Build and execute the query.
    $results = $this->executeFilteredQuery($filter_fields, $filter_values, $per_page, $sort);

    // Get the total count (without pager limit).
    $total_count = $this->getTotalCount($filter_fields, $filter_values);

    // Build results table.
    $build['results'] = $this->buildResultsTable($results, $filter_fields, $filter_values, $total_count);

    return $build;
  }

  /**
   * Sortable columns: visible header label => Job field to sort the query by.
   *
   * Only columns backed by a real Job field can be sorted server-side (across
   * all pages). Keys MUST match the header cell 'data' labels in
   * buildResultsTable(), since tablesort identifies the active column by label.
   *
   * @return array
   *   Map of header label to Job field machine name.
   */
  protected function sortableColumns(): array {
    return [
      'Job No.' => 'title',
      'Client Name' => 'field_client_name',
      'Party To Be Served' => 'field_served_party',
      'Deadline' => 'field_deadline',
      'Case Status' => 'field_case_status',
    ];
  }

  /**
   * Reads the active tablesort column and direction from the request.
   *
   * The table theme renders header sort links as ?order=<label>&sort=asc|desc.
   * The clicked label is mapped back to a Job field for the entity query.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   ['field' => string|null, 'dir' => 'ASC'|'DESC'].
   */
  protected function getSortContext(Request $request): array {
    $order = (string) $request->query->get('order', '');
    $dir = (strtoupper((string) $request->query->get('sort', 'asc')) === 'DESC') ? 'DESC' : 'ASC';
    $map = $this->sortableColumns();
    return [
      'field' => ($order !== '' && isset($map[$order])) ? $map[$order] : NULL,
      'dir' => $dir,
    ];
  }

  /**
   * Executes the filtered entity query.
   */
  protected function executeFilteredQuery(array $filter_fields, array $filter_values, int $per_page = 10, array $sort = ['field' => NULL, 'dir' => 'ASC']): array {
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'job')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->pager($per_page);

    // Server-side column sort when a sortable header is clicked; the default
    // "most recently changed" order remains as a stable tiebreaker.
    if (!empty($sort['field'])) {
      $query->sort($sort['field'], ($sort['dir'] === 'DESC') ? 'DESC' : 'ASC');
    }
    $query->sort('changed', 'DESC');

    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'job');

    // Apply each filter condition (AND logic).
    foreach ($filter_fields as $index => $field_name) {
      $value = $filter_values[$index] ?? '';
      if (empty($field_name) || $value === '') {
        continue;
      }

      // Synthetic milestone "(Done?)" filter: match on whether the underlying
      // admin date field is set (yes) or empty (no).
      if (strpos($field_name, 'milestone__') === 0) {
        $date_field = substr($field_name, 11);
        if (isset($field_definitions[$date_field])) {
          if ($value === 'yes') {
            $query->exists($date_field);
          }
          elseif ($value === 'no') {
            $query->notExists($date_field);
          }
        }
        continue;
      }

      if (!isset($field_definitions[$field_name])) {
        continue;
      }

      $field_type = $field_definitions[$field_name]->getType();

      switch ($field_type) {
        case 'string':
        case 'string_long':
        case 'text':
        case 'text_long':
        case 'email':
        case 'telephone':
          // Text fields use CONTAINS matching.
          $query->condition($field_name, $value, 'CONTAINS');
          break;

        case 'datetime':
          // Date fields: match on the date portion.
          $query->condition($field_name, $value, '>=');
          $next_day = date('Y-m-d', strtotime($value . ' +1 day'));
          $query->condition($field_name, $next_day, '<');
          break;

        case 'list_string':
        case 'boolean':
        case 'integer':
        case 'decimal':
        case 'float':
        case 'entity_reference':
          // Exact match.
          $query->condition($field_name, $value);
          break;

        default:
          $query->condition($field_name, $value);
          break;
      }
    }

    $nids = $query->execute();

    if (empty($nids)) {
      return [];
    }

    return \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);
  }

  /**
   * Builds the results table render array.
   */
  protected function getTotalCount(array $filter_fields, array $filter_values): int {
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'job')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->count();

    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'job');

    foreach ($filter_fields as $index => $field_name) {
      $value = $filter_values[$index] ?? '';
      if (empty($field_name) || $value === '') {
        continue;
      }

      // Synthetic milestone "(Done?)" filter — mirror executeFilteredQuery.
      if (strpos($field_name, 'milestone__') === 0) {
        $date_field = substr($field_name, 11);
        if (isset($field_definitions[$date_field])) {
          if ($value === 'yes') {
            $query->exists($date_field);
          }
          elseif ($value === 'no') {
            $query->notExists($date_field);
          }
        }
        continue;
      }

      if (!isset($field_definitions[$field_name])) {
        continue;
      }

      $field_type = $field_definitions[$field_name]->getType();

      switch ($field_type) {
        case 'string':
        case 'string_long':
        case 'text':
        case 'text_long':
        case 'email':
        case 'telephone':
          $query->condition($field_name, $value, 'CONTAINS');
          break;

        case 'datetime':
          $query->condition($field_name, $value, '>=');
          $next_day = date('Y-m-d', strtotime($value . ' +1 day'));
          $query->condition($field_name, $next_day, '<');
          break;

        default:
          $query->condition($field_name, $value);
          break;
      }
    }

    return (int) $query->execute();
  }

  /**
   * Builds the results table render array.
   */
  protected function buildResultsTable(array $results, array $filter_fields, array $filter_values, int $total_count): array {
    $has_active_filters = FALSE;
    foreach ($filter_fields as $i => $f) {
      if (!empty($f) && ($filter_values[$i] ?? '') !== '') {
        $has_active_filters = TRUE;
        break;
      }
    }

    // Columns backed by a real Job field get a 'field' key so the table theme
    // renders a sort link; Postal Code and Allocated To are derived live from
    // related entities (paragraph / referenced user) and stay non-sortable.
    $header = [
      ['data' => $this->t('Job No.'), 'field' => 'title'],
      ['data' => $this->t('Client Name'), 'field' => 'field_client_name'],
      ['data' => $this->t('Party To Be Served'), 'field' => 'field_served_party'],
      $this->t('Postal Code'),
      ['data' => $this->t('Deadline'), 'field' => 'field_deadline'],
      $this->t('Allocated To'),
      ['data' => $this->t('Case Status'), 'field' => 'field_case_status'],
      $this->t('Operations'),
    ];

    $rows = [];
    foreach ($results as $node) {
      $edit_url = $node->toUrl('edit-form')
        ->setOption('attributes', ['target' => '_blank']);
      $rows[] = [
        Link::fromTextAndUrl(
          $node->getTitle(),
          $edit_url
        )->toString(),
        $this->clientLink($node),
        $node->get('field_served_party')->value ?? '',
        $this->primaryPostalCode($node),
        $node->get('field_deadline')->value ?? '',
        $this->allocatedToName($node),
        $node->get('field_case_status')->value ?? '',
        Link::fromTextAndUrl(
          $this->t('Edit'),
          $edit_url
        )->toString(),
      ];
    }

    $build = [];

    $count_label = $has_active_filters
      ? $this->t('@count job(s) found.', ['@count' => $total_count])
      : $this->t('Total jobs: @count', ['@count' => $total_count]);

    $build['count'] = [
      '#markup' => '<p id="job-filter-results" class="job-filter-count">' . $count_label . '</p>',
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $has_active_filters ? $this->t('No jobs match the selected filters.') : $this->t('Use the filters above to search for jobs.'),
      '#attributes' => ['id' => 'job-filter-results-table', 'class' => ['job-filter-results']],
    ];

    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

  /**
   * Resolves the "Allocated To" display name from the live user reference.
   *
   * field_allocated_to is an ENTITY REFERENCE to a user (not a text field).
   * The column previously read field_allocated_to_full_name, a denormalised
   * text field only ever filled client-side by theme JS at edit time, so it
   * went stale/blank whenever that JS didn't run (per-environment form-display
   * / Chosen / script differences). Loading the referenced user always
   * reflects the DB. Name format matches the rest of the app: the user's
   * field_address given + family name, falling back to the username.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The job node.
   *
   * @return string
   *   The allocated user's display name, or '' when unassigned.
   */
  protected function allocatedToName($node): string {
    if (!$node->hasField('field_allocated_to') || $node->get('field_allocated_to')->isEmpty()) {
      return '';
    }
    // Load the referenced user entity.
    $user = $node->get('field_allocated_to')->entity;
    if (!$user) {
      return '';
    }
    if ($user->hasField('field_address') && !$user->get('field_address')->isEmpty()) {
      $address = $user->get('field_address')->first()->getValue();
      $full_name = trim(($address['given_name'] ?? '') . ' ' . ($address['family_name'] ?? ''));
      if ($full_name !== '') {
        return $full_name;
      }
    }
    return (string) $user->getDisplayName();
  }

  /**
   * Resolves the postal code live from the primary Service Address paragraph.
   *
   * The column previously read field_primary_postal_code, a denormalised string
   * field only ever filled client-side by theme JS when the "Primary Address"
   * checkbox is toggled — so it went stale/blank when that JS didn't run.
   * Instead, read it live: walk the job's Service Address paragraphs
   * (field_service_address -> "addresses" paragraphs), prefer the one flagged
   * primary (field_primary_address), and fall back to the first available
   * service address's postal_code when none is flagged primary. Returns '' when
   * the job has no service address, or none carries a postal code.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The job node.
   *
   * @return string
   *   The primary (or first available) service address postal code, or '' when
   *   no service address has a postal code.
   */
  protected function primaryPostalCode($node): string {
    // No service address at all -> no postal code.
    if (!$node->hasField('field_service_address') || $node->get('field_service_address')->isEmpty()) {
      return '';
    }
    $fallback = '';
    foreach ($node->get('field_service_address')->referencedEntities() as $paragraph) {
      // Skip paragraphs with no address value.
      if (!$paragraph->hasField('field_service_address')
        || $paragraph->get('field_service_address')->isEmpty()) {
        continue;
      }
      $address = $paragraph->get('field_service_address')->first()->getValue();
      $postal = !empty($address['postal_code']) ? (string) $address['postal_code'] : '';
      // Skip addresses that have no postal code.
      if ($postal === '') {
        continue;
      }
      // The address flagged primary wins immediately.
      if ($paragraph->hasField('field_primary_address')
        && (bool) $paragraph->get('field_primary_address')->value) {
        return $postal;
      }
      // Otherwise remember the first available postal code as a fallback.
      if ($fallback === '') {
        $fallback = $postal;
      }
    }
    // No primary; return the first available postal code, or '' if none.
    return $fallback;
  }

  /**
   * Renders the Client column as a link to the referenced client's edit form.
   *
   * Keeps the displayed name (field_client_name, falling back to the client
   * node label) but wraps it in a link to the client node edit form, opened in
   * a new tab. Returns plain text when the job has no client reference.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The job node.
   *
   * @return string|\Drupal\Core\GeneratedLink
   *   Linked client name, or plain text when no client is referenced.
   */
  protected function clientLink($node) {
    $name = ($node->hasField('field_client_name') ? ($node->get('field_client_name')->value ?? '') : '');
    $client = ($node->hasField('field_client') && !$node->get('field_client')->isEmpty())
      ? $node->get('field_client')->entity
      : NULL;
    if (!$client) {
      return $name;
    }
    if ($name === '') {
      $name = $client->label();
    }
    $url = $client->toUrl('edit-form')
      ->setOption('attributes', ['target' => '_blank']);
    return Link::fromTextAndUrl($name, $url)->toString();
  }

}
