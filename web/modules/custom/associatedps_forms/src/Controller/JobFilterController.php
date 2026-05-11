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

    // Build and execute the query.
    $results = $this->executeFilteredQuery($filter_fields, $filter_values, $per_page);

    // Get the total count (without pager limit).
    $total_count = $this->getTotalCount($filter_fields, $filter_values);

    // Build results table.
    $build['results'] = $this->buildResultsTable($results, $filter_fields, $filter_values, $total_count);

    return $build;
  }

  /**
   * Executes the filtered entity query.
   */
  protected function executeFilteredQuery(array $filter_fields, array $filter_values, int $per_page = 10): array {
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'job')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->sort('changed', 'DESC')
      ->pager($per_page);

    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'job');

    // Apply each filter condition (AND logic).
    foreach ($filter_fields as $index => $field_name) {
      $value = $filter_values[$index] ?? '';
      if (empty($field_name) || $value === '') {
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

    $header = [
      $this->t('Job No.'),
      $this->t('Client Name'),
      $this->t('Party To Be Served'),
      $this->t('Postal Code'),
      $this->t('Deadline'),
      $this->t('Allocated To'),
      $this->t('Case Status'),
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
        $node->get('field_client_name')->value ?? '',
        $node->get('field_served_party')->value ?? '',
        $node->get('field_primary_postal_code')->value ?? '',
        $node->get('field_deadline')->value ?? '',
        $node->get('field_allocated_to_full_name')->value ?? '',
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

}
