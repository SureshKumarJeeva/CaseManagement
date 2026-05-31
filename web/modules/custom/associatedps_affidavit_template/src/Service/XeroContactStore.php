<?php

namespace Drupal\associatedps_affidavit_template\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Persists the Drupal Client node → Xero ContactID mapping.
 *
 * Wraps the xero_client_contacts table. Used by:
 *  - hook_node_insert / hook_node_update to record the ID returned by Xero
 *  - the bulk Sync Contacts batch to backfill IDs for existing clients
 *  - XeroInvoiceIntegration to route invoices to the correct Xero contact
 */
class XeroContactStore {

  protected Connection $database;
  protected TimeInterface $time;

  public function __construct(Connection $database, TimeInterface $time) {
    $this->database = $database;
    $this->time = $time;
  }

  /**
   * Returns the stored Xero ContactID for a Client node, or NULL.
   */
  public function get(int $nid): ?string {
    $value = $this->database
      ->select('xero_client_contacts', 'x')
      ->fields('x', ['xero_contact_id'])
      ->condition('nid', $nid)
      ->execute()
      ->fetchField();
    return $value !== FALSE && $value !== NULL && $value !== '' ? (string) $value : NULL;
  }

  /**
   * Upserts the ContactID for a Client node and bumps synced_at.
   */
  public function set(int $nid, string $xero_contact_id): void {
    if ($xero_contact_id === '') {
      return;
    }
    $this->database->merge('xero_client_contacts')
      ->key('nid', $nid)
      ->fields([
        'xero_contact_id' => $xero_contact_id,
        'synced_at' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

}
