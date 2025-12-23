<?php

namespace Drupal\associatedps_affidavit_template\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class CustomTemplateDocuments extends ControllerBase {

  /**
   * Get form ID.
   */
  public function getFormId() {
    return 'custom_template_documents';
  }

  /**
   * List out the job's template documents.
   */
  public function listTemplateDocuments() {
    $limit = 10; // Number of items per page
    $header = [
              'Job ID',
              'Job Title',
              'Document',
              'Template',
              'Operations',
            ];

    // Fetch all nodes of type 'template'
    $query = \Drupal::entityQuery('node')
    ->condition('type', 'job')
    ->accessCheck(TRUE)
    ->sort('created', 'DESC')
    ->pager($limit);

    $nids = $query->execute();
    $rows = [];

    if ($nids) {
        $nodes = Node::loadMultiple($nids);
        foreach ($nodes as $node) {
          \Drupal::logger('associatedps_affidavit_template')->notice('Node ID: @nid, Title: @title, Documents: @doc', ['@nid' => $node->id(), '@title' => $node->label(),  '@doc' =>print_r($node->get('field_documents')->getValue(), TRUE)]);
          foreach ($node->get('field_documents') as $doc_group) {
              $doc_items = $doc_group->entity;   // full entity object
              foreach ($doc_items->get('field_document') as $doc_item) {
                  $doc = $doc_item->entity;   // full entity object
                  $doc_id = $doc->id();
                  $doc_title = $doc->getTitle();

                  $doc_node = Node::load($doc_id);
                  $template_id = $doc_node->get('field_affidavit_template')?->target_id;
                  $template = Node::load($template_id);
                  $template_title = $template?->getTitle();

                  $print_url = $save_url = "";
                  $operations = [];

                  if($template_id){
                    $print_url = Url::fromRoute('associatedps_affidavit_template.print_document_template', ['id' => $node->id()."-".$template_id], ['attributes' => ['target' => '_blank']]);   // adjust route
                    $save_url = Url::fromRoute('associatedps_affidavit_template.save_document_template', ['id' => $node->id()."-".$template_id]);   // adjust route
                    $operations[] = [
                                        'data' => [
                                          '#type' => 'operations',
                                          '#links' => [
                                            'print' => [
                                              'title' => $this->t('Print'),
                                              'url' => $print_url,
                                            ],
                                            'save' => [
                                              'title' => $this->t('Save'),
                                              'url' => $save_url,
                                            ]
                                          ],
                                        ],
                                      ];
                  }
                  $rows[] = [
                            'data'=> [
                                      $node->id(),
                                      Link::fromTextAndUrl($node->label(), $node->toUrl()),
                                      $doc_title,
                                      $template_title,
                                      $operations[0] ?? '',
                                    ],
                            ];
            }
          }
        }
    }
    
    $build['template_list'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No Jobs found.'),
    ];

    // Add the pager element.
    $build['pager'] = [
    '#type' => 'pager',
    ];

    return $build;
}

  /*
  * Render Form to view the template of a document
  */
  public function printDocumentTemplate($id) {
    $this->savePrintDocumentTemplate($id, TRUE);
  }

  /*
  * Function to save the template of a document
  */
  public function savePrintDocumentTemplate($id, $print=FALSE) {
    $template_content = "";
    $elements = [];
    $job_id = explode("-", $id)[0];
    $templateid = explode("-", $id)[1];

    $job_list = Node::load($job_id);
    foreach (\Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'job') as $field_name => $field_definition) {
      if (!empty($field_definition->getTargetBundle())) {
        $elements[$field_name]['type'] = $field_definition->getType();
        $elements[$field_name]['label'] = $field_definition->getLabel();
        $elements[$field_name]['target_type'] = $field_definition->getSetting('target_type');
      }
    }

    $template_node = Node::load($templateid);
    if (!$template_node) {
      return [
        '#markup' => $this->t('Template not found.'),
      ];
    }
    $template_type = $template_node->bundle();
    $template_type_label = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->load($template_type)
      ->label();
      //identify if the template is library type or custom template type 
    if(strtolower($template_type_label) == "library"){
      $template_array = _associatedps_pdf_templates_library_data($templateid);
    }
    else{ // custom template type
      $template_array = _associatedps_pdf_templates_custom_data($templateid);
    }
    $template_content = _associatedps_pdf_templates_replace_value($elements, $job_list, $template_array);

    // Configure Dompdf according to your needs
    $options = new Options();
    $options->set('defaultFont', 'Arial');
    $dompdf = new Dompdf($options);

    // Load HTML content
    $dompdf->loadHtml($template_content['body']);

    // (Optional) Setup the paper size and orientation
    $dompdf->setPaper('A4', 'portrait');

    // Render the HTML as PDF
    $dompdf->render();

    if(!$print){
      // Output the generated PDF to Browser (force download)
      $dompdf->stream("template_{$job_id}-{$templateid}.pdf", [
          "Attachment" => true
      ]);
    }
    else{
      // Force browser to open the generated PDF in a new tab
      $dompdf->stream("template_{$job_id}-{$templateid}.pdf", [
          "Attachment" => false
      ]);
    }
  }

  /*
  * Function to save the template of a document
  */
  public function convertDocumentToPDF($nid) {
    
  }
}
