<?php

namespace Drupal\associatedps_pdf_templates\Form;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Form for library template.
 */
class TemplateListForm extends FormBase {

  /**
   * Get form ID.
   */
  public function getFormId() {
    return 'template_list_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $cat_id = NULL, $nid = NULL) {

    global $base_url;
    $db = Database::getConnection();
    $ajax_wrapper = 'ajax-wrapper';
    $entityManager = \Drupal::service('entity_field.manager');
    $fields = $entityManager->getFieldStorageDefinitions('node', 'library');
    $category_options = options_allowed_values($fields['field_category']);
    $form['category_option'] = [
      '#type' => 'radios',
      '#title' => 'Category',
      '#options' => $category_options,
      '#default_value' => 'Correspondence',
      '#ajax' => [
        'callback' => '::templateData',
        'event' => 'change',
        'wrapper' => $ajax_wrapper,
        'effect' => 'fade',
      ],
    ];
    $form['ajax_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $ajax_wrapper,
      ],
    ];
    $category_result = $form_state->getValue('category_option');

    if (empty($category_result)) {
      $category_result = ['Correspondence'];
    }
    $default_value = '';
    if (!empty($category_result)) {

      if ($category_result == 'Affidavit') {

        $query = db_select('node_field_data', 'n');
        $query->fields('n', ['nid', 'title']);

        $query->leftjoin('node__field_documents', 'fd', 'fd.entity_id = n.nid');
        $query->fields('fd', ['field_documents_target_id']);

        $query->leftjoin('paragraph__field_document', 'pd', 'pd.entity_id = fd.field_documents_target_id');
        $query->fields('pd', ['field_document_target_id']);
        $query->condition('n.type', 'job', '=');
        $query->condition('n.nid', $nid, '=');
        $result = $query->execute();
        $library_title = [];
        $primary_doc = 0;
        if ($result) {
          foreach ($result as $key => $value) {
            if ($value->field_documents_target_id) {
              $paragraph = Paragraph::load($value->field_documents_target_id);
              $primary_doc = ($paragraph->field_primary_document->value) ?? 0;
            }
            if ($value->field_document_target_id) {
              $document_data = node_load($value->field_document_target_id);
              $affidavit_doc = $document_data->get('field_affidavit_template')->referencedEntities();
              foreach ($affidavit_doc as $affkey => $affvalue) {
                $library_title[$affvalue->get('nid')->value] = $affvalue->get('title')->value;
                if ($primary_doc === '1') {
                  $default_value = $affvalue->get('nid')->value;
                }
              }
            }
          }
        }
      }

      else {

        $query = db_select('node_field_data', 'n');
        $query->fields('n', ['nid', 'title']);

        $query->leftjoin('node__field_category', 'fc', 'fc.entity_id = n.nid');
        $query->fields('fc', ['field_category_value']);

        $query->leftjoin('node__field_job_types', 'fj', 'fj.entity_id = n.nid');
        $query->fields('fj', ['field_job_types_target_id']);

        $query->leftjoin('node__field_case_status', 'fs', 'fs.entity_id = n.nid');
        $query->fields('fs', ['field_case_status_value']);

        if (!empty($category_result)) {
          $query->condition('fc.field_category_value', $category_result, '=');
        }
        $query->condition('n.type', 'library', '=');
        $result = $query->execute();

        $node = node_load($nid);
        $job_type = $node->get('field_job_types')->getValue();
        $job_type = reset($job_type);
        $case_status = $node->field_case_status->value;
        $library_title = [];

        foreach ($result as $key => $value) {

          $library_title[$value->nid] = $value->title;
          if (($value->field_job_types_target_id === $job_type['target_id']) && ($value->field_case_status_value === $case_status)) {
            $default_value = $value->nid;
          }
        }
      }

      if (empty($library_title)) {
        $library_title['_none'] = '- Select -';
      }

      if (!empty($default_value) && !empty($library_title)) {
        TemplateListForm::moveToTop($library_title, $default_value);
      }

      $form['ajax_container']['templates'] = [
        '#type' => 'select',
        '#title' => 'Templates',
        '#options' => $library_title,
        '#required' => TRUE,
        '#default_value' => $default_value,
      ];

      if ($category_result == 'Email') {
        $form['ajax_container']['actions'] = ['#type' => 'actions'];
        $form['ajax_container']['actions']['submit']['email'] = [
          '#type' => 'submit',
          '#value' => t('Generate Email'),
        ];
      }
      else {
        $form['ajax_container']['actions'] = ['#type' => 'actions'];
        $form['ajax_container']['actions']['submit']['download'] = [
          '#type' => 'submit',
          '#value' => t('Download'),
        ];
        $form['ajax_container']['actions']['submit']['preview'] = [
          '#type' => 'submit',
          '#value' => t('Preview'),
        ];
      }

    }
    $form['job'] = [
      '#type' => 'hidden',
      '#value' => $nid,
    ];

    $form['#redirect'] = FALSE;
    return $form;
  }

  /**
   * Template_data().
   */
  public function templateData(array $form, FormStateInterface $form_state) {
    return $form['ajax_container'];
  }

  /**
   * SubmitForm().
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $template = $form_state->getValue('templates');
    $job_nid = $form_state->getValue('job');
    global $base_url;
    $params = ['view_args' => [$template], 'job' => $job_nid];

    $triggerElement = $form_state->getTriggeringElement();
    $submit_id = $triggerElement['#id'];

    $url = $base_url . '/print/view/pdf/library_pdf_template/page_1?view_args[0]=' . $template . '&job=' . $job_nid . '&submit_id=' . $submit_id;
    $response = new RedirectResponse($url);
    $response->send();

  }

  /**
   * Move array at top.
   */
  public function moveToTop(&$array, $key) {
    $temp = [$key => $array[$key]];
    unset($array[$key]);
    $array = $temp + $array;
  }

}
