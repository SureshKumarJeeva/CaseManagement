<?php

namespace Drupal\aps_dav_spike\Controller;

use Drupal\Core\Controller\ControllerBase;
use Sabre\DAV\FS\Directory;
use Sabre\DAV\Locks\Backend\File as LockFileBackend;
use Sabre\DAV\Locks\Plugin as LocksPlugin;
use Sabre\DAV\Server;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0 spike: a minimal WebDAV endpoint, to answer one question.
 *
 * THROWAWAY. Delete this module once the question is answered. The code does
 * not carry over to the real feature — what carries over is the knowledge of
 * whether the round trip works, and the three findings noted below.
 *
 * The question: when a user clicks ms-word:ofe|u|<url>, does Word open the
 * document for EDITING and does Ctrl+S write back to the server copy?
 *
 * Everything else in the Affidavit Management plan assumes the answer is yes,
 * which is why this runs before any of it gets built.
 */
class DavSpikeController extends ControllerBase {

  /**
   * The spike's capability token.
   *
   * Fixed and readable so curl commands are easy to retype by hand. The real
   * implementation mints a 64-char random token per edit session with a TTL,
   * an owner and a revoke path — see Phase 1 of the plan. Do not copy this
   * constant into anything that outlives the spike.
   */
  const TOKEN = 'spike-token-delete-me';

  /**
   * Serve the spike directory over WebDAV.
   */
  public function handle(string $token) {
    if (!hash_equals(self::TOKEN, $token)) {
      return new Response('Forbidden.', 403);
    }

    $root = $this->spikeRoot();
    if (!is_dir($root)) {
      return new Response('Spike directory missing: ' . $root, 500);
    }

    // Serving a Directory rather than a single File is deliberate: Word issues
    // PROPFIND against the containing collection as well as the document, and
    // it also writes sidecar lock/owner files next to the document while an
    // edit is open.
    $server = new Server(new Directory($root));

    // Sabre works out node paths by stripping the base URI off the request
    // path. Get this wrong and every PROPFIND resolves to the wrong node, which
    // surfaces as a confusing 404 mid-handshake rather than an obvious error.
    $server->setBaseUri('/aps-dav-spike/' . rawurlencode($token) . '/');

    // Without the locking plugin the OPTIONS response advertises "DAV: 1, 3"
    // and Word — which wants level 2 for an exclusive write lock — falls back
    // to opening a read-only copy. This single line is the difference between
    // the feature working and failing quietly.
    $server->addPlugin(new LocksPlugin(new LockFileBackend($this->lockFile())));

    // Sabre writes its own status line, headers and body straight to the SAPI,
    // so control must not return to Drupal's kernel afterwards — it would
    // append the rendered page to a binary .docx response. exit() is acceptable
    // in a throwaway spike; the real implementation returns a StreamedResponse
    // so Drupal keeps ownership of the response lifecycle.
    $server->exec();
    exit();
  }

  /**
   * Directory served over DAV — deliberately OUTSIDE the docroot.
   *
   * Inside sites/default/files/ Apache would match the file with -f and serve
   * it statically, bypassing Drupal entirely. That path emits no DAV header, so
   * Word would open a read-only copy and we would be testing the exact failure
   * mode this spike exists to rule out.
   */
  protected function spikeRoot(): string {
    return DRUPAL_ROOT . '/../private/dav_spike';
  }

  /**
   * Backing file for the lock store (a file, not a directory).
   */
  protected function lockFile(): string {
    return DRUPAL_ROOT . '/../private/dav_spike_locks';
  }

}
