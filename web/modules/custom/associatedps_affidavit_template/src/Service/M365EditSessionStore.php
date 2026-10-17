<?php

namespace Drupal\associatedps_affidavit_template\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Remembers which SharePoint file belongs to which Library node.
 *
 * The ids in play are minted by three different systems and only one of them is
 * ours: the Library node id. Microsoft's driveId and itemId mean nothing outside
 * their tenant, and are handed to us only once — in the response to the upload.
 * If they are not written down at that moment, the Done button has no way to
 * find the file again after the author has wandered off to another tab.
 *
 * This is the same job docusign_envelopes does for DocuSign (library_nid ->
 * template_id), kept in a separate table because it is a different vendor with
 * a different lifecycle.
 *
 * @see associatedps_affidavit_template_update_8012()
 */
class M365EditSessionStore {

  protected const TABLE = 'm365_edit_sessions';

  protected Connection $database;
  protected TimeInterface $time;

  public function __construct(Connection $database, TimeInterface $time) {
    $this->database = $database;
    $this->time = $time;
  }

  /**
   * Record a newly opened editing session.
   *
   * Any session already open for this node is abandoned first. Two concurrent
   * sessions on one template would mean two SharePoint copies and a coin-flip
   * over which one the Done button pulls back — for a court document that is
   * not an acceptable ambiguity.
   *
   * @param int $nid
   *   Library node id.
   * @param string $drive_id
   *   SharePoint drive id.
   * @param string $item_id
   *   SharePoint driveItem id.
   * @param string $file_name
   *   Name the file was uploaded under.
   * @param string $c_tag
   *   The item's cTag at upload time.
   * @param int $uid
   *   User starting the session.
   *
   * @return int
   *   The new session's primary key.
   */
  public function open(int $nid, string $drive_id, string $item_id, string $file_name, string $c_tag, int $uid): int {
    $now = $this->time->getRequestTime();

    $this->abandonOpen($nid);

    return (int) $this->database->insert(self::TABLE)
      ->fields([
        'library_nid' => $nid,
        'drive_id' => $drive_id,
        'item_id' => $item_id,
        'file_name' => $file_name,
        'c_tag' => $c_tag,
        'uid' => $uid,
        'status' => 'open',
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();
  }

  /**
   * The open session for a node, if there is one.
   *
   * Newest first, so that even if something has gone wrong and several rows are
   * open, the one the author most recently started wins.
   *
   * @return array|null
   *   The row as an associative array, or NULL.
   */
  public function getOpen(int $nid): ?array {
    $row = $this->database->select(self::TABLE, 's')
      ->fields('s')
      ->condition('library_nid', $nid)
      ->condition('status', 'open')
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $row ?: NULL;
  }

  /**
   * Mark a session finished, the file having been written back to the node.
   */
  public function markPulled(int $id): void {
    $this->setStatus($id, 'pulled');
  }

  /**
   * Mark a session abandoned without pulling anything back.
   */
  public function markAbandoned(int $id): void {
    $this->setStatus($id, 'abandoned');
  }

  /**
   * Abandon every open session for a node.
   */
  public function abandonOpen(int $nid): void {
    $this->database->update(self::TABLE)
      ->fields([
        'status' => 'abandoned',
        'updated' => $this->time->getRequestTime(),
      ])
      ->condition('library_nid', $nid)
      ->condition('status', 'open')
      ->execute();
  }

  /**
   * Mark a session whose SharePoint copy could not be removed yet.
   *
   * Word for the web holds a lock on a document for several minutes after the
   * last editor closes, so deleting it immediately after the author presses
   * Done returns HTTP 423 resourceLocked. That is normal, not a failure: the
   * document is already safely on our server by then. The row is parked here
   * and cron retries the delete once the lock has expired.
   */
  public function markCleanupPending(int $id): void {
    $this->setStatus($id, 'cleanup_pending');
  }

  /**
   * Sessions whose remote copy still needs deleting, old enough to retry.
   *
   * @param int $older_than
   *   Unix timestamp; only rows last touched before this are returned, so a
   *   retry never runs while the lock is still likely to be held.
   *
   * @return array[]
   */
  public function getCleanupPending(int $older_than): array {
    return $this->database->select(self::TABLE, 's')
      ->fields('s')
      ->condition('status', 'cleanup_pending')
      ->condition('updated', $older_than, '<')
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
  }

  /**
   * Sessions left open longer than a cutoff.
   *
   * Authors close tabs without pressing Done. Those rows keep a SharePoint copy
   * of a court document alive indefinitely, so cron sweeps them up.
   *
   * @param int $older_than
   *   Unix timestamp. Sessions created before this are returned.
   *
   * @return array[]
   */
  public function getStale(int $older_than): array {
    return $this->database->select(self::TABLE, 's')
      ->fields('s')
      ->condition('status', 'open')
      ->condition('created', $older_than, '<')
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
  }

  /**
   * Set a session's status and stamp it.
   */
  protected function setStatus(int $id, string $status): void {
    $this->database->update(self::TABLE)
      ->fields([
        'status' => $status,
        'updated' => $this->time->getRequestTime(),
      ])
      ->condition('id', $id)
      ->execute();
  }

}
