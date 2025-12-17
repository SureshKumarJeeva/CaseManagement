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
                    $print_url = Url::fromRoute('associatedps_affidavit_template.print_document_template', ['nid' => $template_id]);   // adjust route
                    $save_url = Url::fromRoute('associatedps_affidavit_template.save_document_template', ['nid' => $template_id]);   // adjust route
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
  public function printDocumentTemplate($nid) {
    $node = Node::load($nid);
    if (!$node) {
      return [
        '#markup' => $this->t('Template not found.'),
      ];
    }

    $template_content = $node->get('body')->value ?? $this->t('No content available.');

    return [
      '#type' => 'markup',
      '#markup' => $template_content,
    ];
  }

  /*
  * Function to save the template of a document
  */
  public function saveDocumentTemplate($nid) {
    $node = Node::load($nid);
    if (!$node) {
      return [
        '#markup' => $this->t('Template not found.'),
      ];
    }

    $template_content = $node->get('body')->value ?? $this->t('No content available.');

    // Configure Dompdf according to your needs
    $options = new Options();
    $options->set('defaultFont', 'Arial');
    $dompdf = new Dompdf($options);

    // Load HTML content
    $dompdf->loadHtml($template_content);

    // (Optional) Setup the paper size and orientation
    $dompdf->setPaper('A4', 'portrait');

    // Render the HTML as PDF
    $dompdf->render();

    // Output the generated PDF to Browser (force download)
    $dompdf->stream("template_{$nid}.pdf", [
        "Attachment" => true
    ]);

    // $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($filename);
    // $response->setContentDisposition(\Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);

    // return $response;   
  }

  /*
  * Function to save the template of a document
  */
  public function convertDocumentToPDF($nid) {
    
  }
}
