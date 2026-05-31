<?php

namespace Drupal\associatedps_affidavit_template\Service;

use Drupal\node\NodeInterface;

/**
 * Maps a Drupal Client node into a Xero Contacts API row.
 *
 * Shared by the bulk Sync Contacts batch and the hook_node_insert auto-sync
 * so there is one source of truth for the field mapping.
 */
class XeroContactMapper {

  /**
   * Build a single Xero Contact row from a Drupal Client node.
   *
   * When $xero_contact_id is supplied, it is emitted in the row so Xero
   * matches by ID and updates the existing contact — covers renames and
   * prevents duplicate contacts on re-sync.
   *
   * @return array
   *   Associative array suitable for inclusion in the Xero
   *   { "Contacts": [ ... ] } payload.
   */
  public function mapClientNode(NodeInterface $node, ?string $xero_contact_id = NULL): array {
    $row = [
      'Name' => (string) $node->label(),
    ];

    if ($xero_contact_id !== NULL && $xero_contact_id !== '') {
      $row['ContactID'] = $xero_contact_id;
    }

    if ($node->hasField('field_client_id') && !$node->get('field_client_id')->isEmpty()) {
      $row['ContactNumber'] = (string) $node->get('field_client_id')->value;
    }

    if ($node->hasField('field_email') && !$node->get('field_email')->isEmpty()) {
      $email = trim((string) $node->get('field_email')->value);
      if ($email !== '') {
        $row['EmailAddress'] = $email;
      }
    }

    if ($node->hasField('field_telephone') && !$node->get('field_telephone')->isEmpty()) {
      $phone = trim((string) $node->get('field_telephone')->value);
      if ($phone !== '') {
        $row['Phones'] = [
          [
            'PhoneType' => 'DEFAULT',
            'PhoneNumber' => $phone,
          ],
        ];
      }
    }

    if ($node->hasField('field_client_address') && !$node->get('field_client_address')->isEmpty()) {
      $addr = $node->get('field_client_address')->first()->getValue();
      $address = array_filter([
        'AddressType' => 'STREET',
        'AddressLine1' => $addr['address_line1'] ?? '',
        'AddressLine2' => $addr['address_line2'] ?? '',
        'City' => $addr['locality'] ?? '',
        'Region' => $addr['administrative_area'] ?? '',
        'PostalCode' => $addr['postal_code'] ?? '',
        'Country' => $addr['country_code'] ?? '',
      ], static fn ($v) => $v !== '' && $v !== NULL);

      if (count($address) > 1) {
        $row['Addresses'] = [$address];
      }
    }

    return $row;
  }

}
