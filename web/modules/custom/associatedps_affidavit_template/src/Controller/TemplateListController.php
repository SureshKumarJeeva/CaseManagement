<?php

namespace Drupal\associatedps_affidavit_template\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Drupal\Core\Link;
use Drupal\Core\Url;

class TemplateListController extends ControllerBase {

    public function listTemplates() {
        $limit = 10; // Number of items per page
        $header = [
            'nid' => $this->t('ID'),
            'title' => $this->t('Title'),
            'created' => $this->t('Created'),
            'operations' => $this->t('Operations'),
        ];

        // Fetch all nodes of type 'template'
        $query = \Drupal::entityQuery('node')
        ->condition('type', 'template')
        ->accessCheck(TRUE)
        ->sort('created', 'DESC')
        ->pager($limit);

        $nids = $query->execute();
        $rows = [];

        if ($nids) {
            $nodes = Node::loadMultiple($nids);
            foreach ($nodes as $node) {
                $edit_url = Url::fromRoute('entity.node.edit_form', ['node' => $node->id()]);
                $rows[] = [
                'nid' => $node->id(),
                'title' => Link::fromTextAndUrl($node->label(), $node->toUrl()),
                'created' => date('Y-m-d H:i', $node->getCreatedTime()),
                'operations' => Link::fromTextAndUrl($this->t('Edit'), $edit_url),
                ];
            }
        }

        $build['template_list'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No templates found.'),
        ];

        // Add the pager element.
        $build['pager'] = [
        '#type' => 'pager',
        ];

        return $build;
    }
}