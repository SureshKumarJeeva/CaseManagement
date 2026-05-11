<?php

namespace Drupal\associatedps_forms\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Dynamic multi-field filter form for Job nodes.
 */
class JobFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'job_filter_form';
  }

  /**
   * Returns the list of filterable Job fields with metadata.
   */
  protected function getFilterableFields(): array {
    $fields = [];
    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'job');

    // Field types we support filtering on.
    $supported_types = [
      'list_string',
      'entity_reference',
      'string',
      'string_long',
      'text',
      'text_long',
      'boolean',
      'datetime',
      'email',
      'telephone',
      'integer',
      'decimal',
      'float',
    ];

    // Skip paragraph references and internal/computed fields.
    $skip_fields = [
      'field_documents',
      'field_affidavits',
      'field_cover_letters',
      'field_job_fees',
      'field_attendance_notes',
      'field_parties',
      'field_party_2',
      'field_para_address',
    ];

    foreach ($field_definitions as $field_name => $definition) {
      // Only include configurable fields (field_*).
      if (strpos($field_name, 'field_') !== 0) {
        continue;
      }
      if (in_array($field_name, $skip_fields)) {
        continue;
      }

      $field_type = $definition->getType();

      // Skip paragraph references.
      if ($field_type === 'entity_reference_revisions') {
        continue;
      }

      if (!in_array($field_type, $supported_types)) {
        continue;
      }

      $fields[$field_name] = [
        'label' => $definition->getLabel(),
        'type' => $field_type,
        'settings' => $definition->getSettings(),
        'storage_settings' => $definition->getFieldStorageDefinition()->getSettings(),
      ];
    }

    // Sort alphabetically by label.
    uasort($fields, function ($a, $b) {
      return strcasecmp($a['label'], $b['label']);
    });

    return $fields;
  }

  /**
   * Returns available options for a given field.
   */
  protected function getFieldOptions(string $field_name, array $field_info): array {
    $type = $field_info['type'];

    switch ($type) {
      case 'list_string':
        $allowed_values = $field_info['storage_settings']['allowed_values'] ?? [];
        $options = [];
        foreach ($allowed_values as $key => $item) {
          if (is_array($item)) {
            $options[$item['value']] = $item['label'];
          }
          else {
            // Simple key => label format.
            $options[$key] = $item;
          }
        }
        return $options;

      case 'boolean':
        return ['1' => $this->t('Checked'), '0' => $this->t('Unchecked')];

      case 'entity_reference':
        return $this->getEntityReferenceOptions($field_name, $field_info);

      default:
        return [];
    }
  }

  /**
   * Gets options for entity reference fields.
   */
  protected function getEntityReferenceOptions(string $field_name, array $field_info): array {
    $options = [];
    $target_type = $field_info['settings']['handler_settings']['target_type']
      ?? $field_info['storage_settings']['target_type']
      ?? NULL;

    if (!$target_type) {
      return $options;
    }

    $handler_settings = $field_info['settings']['handler_settings'] ?? [];

    switch ($target_type) {
      case 'node':
        $target_bundles = $handler_settings['target_bundles'] ?? [];
        $query = \Drupal::entityQuery('node')
          ->condition('status', 1)
          ->accessCheck(TRUE)
          ->sort('title', 'ASC');
        if (!empty($target_bundles)) {
          $query->condition('type', array_keys($target_bundles), 'IN');
        }
        $nids = $query->execute();
        if ($nids) {
          $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);
          foreach ($nodes as $node) {
            $options[$node->id()] = $node->getTitle();
          }
        }
        break;

      case 'user':
        $users = \Drupal::entityTypeManager()->getStorage('user')->loadMultiple();
        foreach ($users as $user) {
          if ($user->id() == 0) {
            continue;
          }
          $name = $user->hasField('field_full_name') && !$user->get('field_full_name')->isEmpty()
            ? $user->get('field_full_name')->value
            : $user->getDisplayName();
          $options[$user->id()] = $name;
        }
        asort($options);
        break;

      case 'taxonomy_term':
        $target_bundles = $handler_settings['target_bundles'] ?? [];
        $query = \Drupal::entityQuery('taxonomy_term')
          ->accessCheck(TRUE)
          ->sort('name', 'ASC');
        if (!empty($target_bundles)) {
          $query->condition('vid', array_keys($target_bundles), 'IN');
        }
        $tids = $query->execute();
        if ($tids) {
          $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadMultiple($tids);
          foreach ($terms as $term) {
            $options[$term->id()] = $term->label();
          }
        }
        break;
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $filterable_fields = $this->getFilterableFields();

    // Build field selector options.
    $field_options = ['' => $this->t('- Select a field -')];
    foreach ($filterable_fields as $field_name => $info) {
      $field_options[$field_name] = $info['label'];
    }

    // Store field metadata for AJAX use.
    $form_state->set('filterable_fields', $filterable_fields);

    // Determine number of filter rows.
    $num_filters = $form_state->get('num_filters');
    if ($num_filters === NULL) {
      // Check if filters were passed via query parameters.
      $request = \Drupal::request();
      $existing_fields = $request->query->all('filter_field');
      $num_filters = !empty($existing_fields) ? count($existing_fields) : 1;
      $form_state->set('num_filters', $num_filters);
    }

    $form['#prefix'] = '<div id="job-filter-form-wrapper">';
    $form['#suffix'] = '</div>';

    $form['filters'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['id' => 'job-filters-container'],
    ];

    $request = \Drupal::request();
    $existing_fields = $request->query->all('filter_field');
    $existing_values = $request->query->all('filter_value');

    for ($i = 0; $i < $num_filters; $i++) {
      $form['filters']['row_' . $i] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['job-filter-row']],
      ];

      // Determine current field selection: user input (AJAX) > query param.
      $input = $form_state->getUserInput();
      $selected_field = $input['filters']['row_' . $i]['filter_field'] ?? NULL;
      if ($selected_field === NULL || $selected_field === '') {
        $selected_field = $existing_fields[$i] ?? '';
      }

      $form['filters']['row_' . $i]['filter_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Field'),
        '#options' => $field_options,
        '#default_value' => $selected_field,
        '#ajax' => [
          'callback' => '::ajaxRefreshForm',
          'wrapper' => 'job-filter-form-wrapper',
          'event' => 'change',
        ],
      ];

      // Build the value widget based on the selected field.
      if (!empty($selected_field) && isset($filterable_fields[$selected_field])) {
        $field_info = $filterable_fields[$selected_field];
        $default_val = $input['filters']['row_' . $i]['filter_value'] ?? NULL;
        if ($default_val === NULL) {
          $default_val = $existing_values[$i] ?? '';
        }

        $form['filters']['row_' . $i]['filter_value'] =
          $this->buildValueWidget($field_info, $selected_field, $default_val);
      }

      // Remove button (only if more than 1 row).
      if ($num_filters > 1) {
        $form['filters']['row_' . $i]['remove'] = [
          '#type' => 'submit',
          '#value' => $this->t('Remove'),
          '#name' => 'remove_' . $i,
          '#submit' => ['::removeFilterRow'],
          '#ajax' => [
            'callback' => '::ajaxRefreshForm',
            'wrapper' => 'job-filter-form-wrapper',
          ],
          '#limit_validation_errors' => [],
          '#attributes' => ['class' => ['button--small', 'button--danger']],
        ];
      }
    }

    $request = \Drupal::request();
    $default_per_page = $request->query->get('per_page', '10');

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['add_filter'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add Filter'),
      '#submit' => ['::addFilterRow'],
      '#ajax' => [
        'callback' => '::ajaxRefreshForm',
        'wrapper' => 'job-filter-form-wrapper',
      ],
      '#limit_validation_errors' => [],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#attributes' => ['class' => ['button--primary']],
    ];

    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => Url::fromRoute('associatedps_forms.job_filter'),
      '#attributes' => ['class' => ['button']],
    ];

    $form['actions']['per_page'] = [
      '#type' => 'select',
      '#title' => $this->t('Results per page'),
      '#wrapper_attributes' => ['class' => ['form-item--per-page']],
      '#options' => [
        '10' => '10',
        '20' => '20',
        '30' => '30',
        '40' => '40',
        '50' => '50',
      ],
      '#default_value' => $default_per_page,
    ];

    return $form;
  }

  /**
   * Builds the value widget based on field type.
   */
  protected function buildValueWidget(array $field_info, string $field_name, $default_value): array {
    $type = $field_info['type'];

    switch ($type) {
      case 'list_string':
        $options = $this->getFieldOptions($field_name, $field_info);
        return [
          '#type' => 'select',
          '#title' => $this->t('Value'),
          '#options' => ['' => $this->t('- Any -')] + $options,
          '#default_value' => $default_value,
        ];

      case 'boolean':
        return [
          '#type' => 'select',
          '#title' => $this->t('Value'),
          '#options' => [
            '' => $this->t('- Any -'),
            '1' => $this->t('Checked'),
            '0' => $this->t('Unchecked'),
          ],
          '#default_value' => $default_value,
        ];

      case 'entity_reference':
        $options = $this->getFieldOptions($field_name, $field_info);
        return [
          '#type' => 'select',
          '#title' => $this->t('Value'),
          '#options' => ['' => $this->t('- Any -')] + $options,
          '#default_value' => $default_value,
        ];

      case 'datetime':
        return [
          '#type' => 'date',
          '#title' => $this->t('Value'),
          '#default_value' => $default_value,
        ];

      case 'integer':
      case 'decimal':
      case 'float':
        return [
          '#type' => 'number',
          '#title' => $this->t('Value'),
          '#default_value' => $default_value,
        ];

      default:
        // String, text, email, telephone — use a text input with CONTAINS.
        return [
          '#type' => 'textfield',
          '#title' => $this->t('Value'),
          '#default_value' => $default_value,
          '#attributes' => ['placeholder' => $this->t('Enter search text...')],
        ];
    }
  }

  /**
   * AJAX callback to refresh the whole form.
   */
  public function ajaxRefreshForm(array &$form, FormStateInterface $form_state): array {
    return $form;
  }

  /**
   * Submit handler to add a filter row.
   */
  public function addFilterRow(array &$form, FormStateInterface $form_state) {
    $num_filters = $form_state->get('num_filters');
    $form_state->set('num_filters', $num_filters + 1);
    $form_state->setRebuild();
  }

  /**
   * Submit handler to remove a filter row.
   */
  public function removeFilterRow(array &$form, FormStateInterface $form_state) {
    $num_filters = $form_state->get('num_filters');
    if ($num_filters > 1) {
      $form_state->set('num_filters', $num_filters - 1);
    }
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $num_filters = $form_state->get('num_filters');
    $filter_fields = [];
    $filter_values = [];

    for ($i = 0; $i < $num_filters; $i++) {
      $field = $form_state->getValue(['filters', 'row_' . $i, 'filter_field']);
      $value = $form_state->getValue(['filters', 'row_' . $i, 'filter_value']);

      if (!empty($field) && $value !== '' && $value !== NULL) {
        $filter_fields[] = $field;
        $filter_values[] = $value;
      }
    }

    // Redirect with query parameters so the URL is bookmarkable.
    $per_page_value = $form_state->getValue('per_page');
    $per_page = $per_page_value ? $per_page_value : '10';

    $url = Url::fromRoute('associatedps_forms.job_filter', [], [
      'query' => [
        'filter_field' => $filter_fields,
        'filter_value' => $filter_values,
        'per_page' => $per_page,
      ],
    ]);
    $form_state->setRedirectUrl($url);
  }

}
