<?php

namespace Drupal\associatedps_forms\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;

/**
 * GetContentNode class.
 */
class GetContentNode extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public function getContent($id) {
    $node_client = Node::load($id);
    $client_name = $node_client->getTitle();
    $client_id = $node_client->get('field_client_id')->getValue()[0]['value'] ? $node_client->get('field_client_id')->getValue()[0]['value'] : '';
    $telephone = $node_client->get('field_telephone')->getValue()[0]['value'] ? $node_client->get('field_telephone')->getValue()[0]['value'] : '';
    $generic_email = $node_client->get('field_email')->getValue()[0]['value'] ? $node_client->get('field_email')->getValue()[0]['value'] : '';
    $discount = $node_client->get('field_agreed_discount')->getValue()[0]['value'] ? $node_client->get('field_agreed_discount')->getValue()[0]['value'] : '';
    $notes = $node_client->get('field_notes')->getValue()[0]['value'] ? $node_client->get('field_notes')->getValue()[0]['value'] : '';
    $sa_address_line1 = $node_client->get('field_client_address')->getValue()[0]['address_line1'] ? $node_client->get('field_client_address')->getValue()[0]['address_line1'] : '';
    $sa_address_line2 = $node_client->get('field_client_address')->getValue()[0]['address_line2'] ? $node_client->get('field_client_address')->getValue()[0]['address_line2'] : '';
    $sa_locality = $node_client->get('field_client_address')->getValue()[0]['locality'] ? $node_client->get('field_client_address')->getValue()[0]['locality'] : '';
    $sa_postal_code = $node_client->get('field_client_address')->getValue()[0]['postal_code'] ? $node_client->get('field_client_address')->getValue()[0]['postal_code'] : '';
    $sa_administrative_area = $node_client->get('field_client_address')->getValue()[0]['administrative_area'] ? $node_client->get('field_client_address')->getValue()[0]['administrative_area'] : '';

    $ouput = '<div class="client-info">';
    $ouput .= '<p><strong>Client Name: </strong><span>' . $client_name . '</span></p>';
    $ouput .= '<p><strong>Client ID: </strong><span>' . $client_id . '</span></p>';
    $ouput .= '<p><strong>Address: </strong><span>' . $sa_address_line1 . '<br>' . $sa_address_line2 . '<br>' . $sa_locality . ' ' . $sa_administrative_area . ' ' . $sa_postal_code . '<br>Australia</span></p>';
    $ouput .= '<p><strong>Telephone: </strong><span>' . $telephone . '</span></p>';
    $ouput .= '<p><strong>Generic Email: </strong><span>' . $generic_email . '</span></p>';
    $ouput .= '<p><strong>Agreed Discount: </strong><span>' . $discount . '</span></p>';
    $ouput .= '<p><strong>Notes: </strong>' . $notes . '</p>';
    $ouput .= '</div>';

    return [
      '#markup' => $ouput,
    ];
  }

}
