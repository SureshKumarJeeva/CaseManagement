<?php

namespace Drupal\associatedps_forms\Form;

use Drupal\associatedps_forms\FormDisplayFields;
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

    // Field set and labels come from FormDisplayFields so this dropdown, the
    // DocuSign token picker and (later) the email-template token list all offer
    // the same fields under the same names. That means paths one level deep
    // ("field_client__mail"), labels read from the live Job form display
    // ("Client-Job Details-Client's Job Email"), and no field that sits outside
    // a tab. See FormDisplayFields for the rules.
    $job_fields = FormDisplayFields::create();

    // Field types we can actually build a value widget and a query condition
    // for. This is the one filter that stays local: it is about what this form
    // can render, not about which fields exist.
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

    foreach ($job_fields->getFieldPaths() as $path) {
      // NULL label = not inside a tab, so not offered here. Old bookmarked
      // URLs naming such a field still filter correctly — see
      // JobFilterController.
      $label = $job_fields->buildLabel($path);
      if ($label === NULL) {
        continue;
      }

      // Sub-field definitions come from the REFERENCED entity, not from Job.
      $definition = $job_fields->getFieldDefinition($path);
      if (!$definition) {
        continue;
      }

      $field_type = $definition->getType();
      if (!in_array($field_type, $supported_types, TRUE)) {
        continue;
      }

      $fields[$path] = [
        'label' => $label,
        'type' => $field_type,
        'settings' => $definition->getSettings(),
        'storage_settings' => $definition->getFieldStorageDefinition()->getSettings(),
      ];
    }

    // Milestone "(Done?)" filters — synthetic Yes/No entries that match on
    // whether an admin date field is set. The Job Status tab renders these as
    // code-built "set to today" checkboxes (associatedps_forms.module) which
    // store nothing themselves, so there is no real field to filter on; instead
    // we filter on the underlying DATE field being set/empty (entity-query
    // exists()/notExists() — see JobFilterController). Keyed milestone__<field>
    // and added here so they sort alphabetically with the real fields below.
    $milestone_fields = [
      'field_admin_affidavit_date' => $this->t('Affidavit Drafted'),
      'field_affidavit_sent_to_agent' => $this->t('Affidavit Sent to Agent'),
      'field_affidavit_sent_to_client' => $this->t('Affidavit Sent to Client'),
      'field_affidavit_received_from_ag' => $this->t('Affidavit Received from Agent'),
      'field_admin_cover_letter_date' => $this->t('Job Logged'),
      'field_admin_email_update_date' => $this->t('Email Update Sent'),
      'field_admin_invoiced_date' => $this->t('Invoice Drafted'),
      'field_admin_send_to_client_date' => $this->t('Invoice Sent to Client'),
      'field_allocation_date' => $this->t('Job Allocated'),
    ];
    $group_paths = $job_fields->getGroupPaths();
    foreach ($milestone_fields as $date_field => $milestone_label) {
      // Skip if the underlying date field doesn't exist on Job (config safety).
      if (!$job_fields->getFieldDefinition($date_field)) {
        continue;
      }

      // A milestone has no form-display placement of its own — the checkbox is
      // built in code — so borrow the tab/section path of the date field it
      // reads, keeping it sorted alongside that tab's real fields. The date
      // field may itself be untabbed, in which case the bare label stands:
      // these are long-standing working filters and are not dropped the way an
      // untabbed real field is.
      $label = $this->t('@label (Done?)', ['@label' => $milestone_label]);
      if (!empty($group_paths[$date_field])) {
        $label = implode(FormDisplayFields::LABEL_SEPARATOR, $group_paths[$date_field])
          . FormDisplayFields::LABEL_SEPARATOR . $label;
      }

      $fields['milestone__' . $date_field] = [
        'label' => $label,
        'type' => 'milestone',
        'milestone_field' => $date_field,
        'settings' => [],
        'storage_settings' => [],
      ];
    }

    // Sort alphabetically by label — with tab-prefixed labels this groups the
    // dropdown by tab, in the same order the user sees on the Job form.
    uasort($fields, function ($a, $b) {
      return strcasecmp((string) $a['label'], (string) $b['label']);
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
          $options[$user->id()] = $this->userDisplayName($user);
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
   * Human name for a user, matching the "Allocated To" column in the results.
   *
   * The results table (JobFilterController::allocatedToName) shows the user's
   * field_address given + family name, falling back to the username. The filter
   * value dropdown must read the SAME source, or a user appears in the table as
   * "Jane Smith" but in the filter as her login name — confusing when picking a
   * value. Kept public and static so the controller uses the one definition.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   *
   * @return string
   *   The display name.
   */
  public static function userDisplayName($user): string {
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

    // "Create New Job" shortcut at the very top of the page (before the first
    // "Field" filter) so job creation is still reachable from this page. Only
    // shown to users who can create Job content.
    $create_url = Url::fromRoute('node.add', ['node_type' => 'job']);
    $form['create_job'] = [
      '#type' => 'link',
      '#title' => $this->t('Create New Job'),
      '#url' => $create_url,
      '#access' => $create_url->access(),
      '#weight' => -100,
      '#attributes' => ['class' => ['button', 'button--primary', 'job-filter-create-job']],
      '#prefix' => '<div class="job-filter-create-job-wrapper">',
      '#suffix' => '</div>',
    ];

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
        // Force Chosen on every environment. Auto-enhancement depends on the
        // site's chosen minimum_single setting, which is drifted between local
        // and staging (Chosen locally, native select on staging); pin it so the
        // field selector looks and behaves the same everywhere.
        '#chosen' => TRUE,
        // Stable wrapper class so the CSS can pin this to a fixed width without
        // relying on child order (the remove button and Drupal wrappers make
        // :nth-of-type unreliable).
        '#wrapper_attributes' => ['class' => ['job-filter-field']],
        '#ajax' => [
          'callback' => '::ajaxRefreshForm',
          'wrapper' => 'job-filter-form-wrapper',
          'event' => 'change',
        ],
      ];

      // If THIS row's field selector is what triggered the AJAX rebuild, the
      // user just switched fields. The previous field's value is still sitting
      // in user input, and because a form element takes its value from user
      // input ahead of #default_value, it would be poured into the new value
      // widget — which may be a different type (a leftover date string landing
      // on an integer/list/reference field), producing a submit that errors the
      // query. Drop the stale value so the new widget starts empty.
      $triggering = $form_state->getTriggeringElement();
      $field_changed = $triggering
        && ($triggering['#array_parents'] ?? NULL) === ['filters', 'row_' . $i, 'filter_field'];
      if ($field_changed) {
        unset($input['filters']['row_' . $i]['filter_value']);
        $form_state->setUserInput($input);
      }

      // Build the value widget based on the selected field.
      if (!empty($selected_field) && isset($filterable_fields[$selected_field])) {
        $field_info = $filterable_fields[$selected_field];
        // After a field switch, ignore both the stale user input and the URL
        // value from a previous search; otherwise keep the current value.
        $default_val = $field_changed
          ? ''
          : ($input['filters']['row_' . $i]['filter_value'] ?? ($existing_values[$i] ?? ''));

        $form['filters']['row_' . $i]['filter_value'] =
          $this->buildValueWidget($field_info, $selected_field, $default_val);
        // Stable wrapper class so the CSS can let the value widget fill the
        // space beside the fixed-width field selector.
        $form['filters']['row_' . $i]['filter_value']['#wrapper_attributes']['class'][] = 'job-filter-value';
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
      // Render as a plain native select, not Chosen. The site's chosen
      // minimum_single is 1, so Chosen would otherwise wrap even this small
      // 5-option select and collapse it so the value isn't visible.
      '#chosen' => FALSE,
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
          // Pin to Chosen (see field selector note); keeps the value box
          // consistent between local and staging despite drifted chosen config.
          '#chosen' => TRUE,
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
          '#chosen' => TRUE,
        ];

      case 'milestone':
        // Synthetic "(Done?)" filter — Yes = date field set, No = empty.
        return [
          '#type' => 'select',
          '#title' => $this->t('Value'),
          '#options' => [
            '' => $this->t('- Any -'),
            'yes' => $this->t('Yes — done'),
            'no' => $this->t('No — not done'),
          ],
          '#default_value' => $default_value,
          '#chosen' => TRUE,
        ];

      case 'entity_reference':
        $options = $this->getFieldOptions($field_name, $field_info);
        return [
          '#type' => 'select',
          '#title' => $this->t('Value'),
          '#options' => ['' => $this->t('- Any -')] + $options,
          '#default_value' => $default_value,
          '#chosen' => TRUE,
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
