<?php

namespace Drupal\aps_dav_spike;

use Sabre\DAV\FS\Directory;
use Sabre\DAV\Locks\Backend\File as LockFileBackend;
use Sabre\DAV\Locks\Plugin as LocksPlugin;
use Sabre\DAV\Server;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Phase 0 spike: a minimal WebDAV endpoint, to answer one question.
 *
 * THROWAWAY. Delete this module once the question is answered. What carries
 * over to the real feature is not this code but the knowledge of whether the
 * round trip works, plus the findings noted throughout.
 *
 * The question: when a user clicks ms-word:ofe|u|<url>, does Word open the
 * document for EDITING, and does Ctrl+S write back to the server copy?
 * Everything else in the Affidavit Management plan assumes yes, which is why
 * this runs before any of it gets built.
 *
 * This is a middleware, not a controller, because a controller cannot serve
 * WebDAV in Drupal at all — see aps_dav_spike.services.yml for the two core
 * subscribers that make it impossible.
 */
class DavMiddleware implements HttpKernelInterface {

  /**
   * Path prefix this middleware answers. Everything else falls through.
   */
  const PATH_PREFIX = '/aps-dav-spike/';

  /**
   * The spike's capability token.
   *
   * Fixed and readable so curl commands are easy to retype by hand. Word has
   * its own HTTP stack and does NOT send the Drupal session cookie, so a
   * permission check would reject every request Word makes; authorisation is
   * this token in the path instead. The real implementation mints a 64-char
   * random token per edit session with a TTL, an owner and a revoke path —
   * see Phase 1 of the plan. Do not copy this constant into anything that
   * outlives the spike.
   */
  const TOKEN = 'spike-token-delete-me';

  /**
   * The next kernel in the stack.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * Constructs the middleware.
   *
   * StackedKernelPass prepends the inner kernel as the first argument, which
   * is why aps_dav_spike.services.yml declares no arguments of its own.
   */
  public function __construct(HttpKernelInterface $http_kernel) {
    $this->httpKernel = $http_kernel;
  }

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
    $path = $request->getPathInfo();

    // Fall through for everything that is not ours. This guard runs in front
    // of every request on the site, so it stays cheap and total: one strpos,
    // no services touched, no container lookups.
    if (strpos($path, self::PATH_PREFIX) !== 0) {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    $rest = substr($path, strlen(self::PATH_PREFIX));
    $token = rawurldecode(explode('/', $rest, 2)[0]);
    if (!hash_equals(self::TOKEN, $token)) {
      return new Response('Forbidden.', 403);
    }

    $root = $this->spikeRoot();
    if (!is_dir($root)) {
      return new Response('Spike directory missing: ' . $root, 500);
    }

    return $this->serveDav($request, $token, $root);
  }

  /**
   * Hands the request to sabre and translates its response back to Symfony.
   */
  protected function serveDav(Request $request, string $token, string $root): Response {
    // Serving a Directory rather than a single File is deliberate: Word issues
    // PROPFIND against the containing collection as well as the document, and
    // writes sidecar lock/owner files next to the document while an edit is
    // open.
    $server = new Server(new Directory($root));

    // Redirect sabre's output through a capture object instead of letting it
    // write to the SAPI. @see \Drupal\aps_dav_spike\CapturingSapi.
    CapturingSapi::$captured = NULL;
    $server->sapi = new CapturingSapi();

    // Sabre works out node paths by stripping the base URI off the request
    // path. Get this wrong and every PROPFIND resolves to the wrong node,
    // surfacing as a confusing mid-handshake 404 rather than an obvious error.
    // getBaseUrl() covers the case of Drupal installed in a subdirectory.
    $server->setBaseUri($request->getBaseUrl() . self::PATH_PREFIX . rawurlencode($token) . '/');

    // Without the locking plugin the OPTIONS response advertises "DAV: 1, 3"
    // and Word — which wants level 2 for an exclusive write lock — falls back
    // to opening a read-only copy. This single line is the difference between
    // the feature working and failing quietly.
    $server->addPlugin(new LocksPlugin(new LockFileBackend($this->lockFile())));

    $server->exec();

    $dav = CapturingSapi::$captured;
    if ($dav === NULL) {
      return new Response('The DAV server produced no response.', 500);
    }

    $response = new Response($this->readBody($dav->getBody()), $dav->getStatus());
    foreach ($dav->getHeaders() as $name => $values) {
      $response->headers->set($name, $values, TRUE);
    }

    return $response;
  }

  /**
   * Normalises a sabre response body to a string.
   *
   * Sabre returns a stream for file downloads, a callable for some generated
   * responses, and a plain string for XML replies such as PROPFIND.
   */
  protected function readBody($body): string {
    if (is_resource($body)) {
      return (string) stream_get_contents($body);
    }
    if (is_callable($body)) {
      ob_start();
      $body();
      return (string) ob_get_clean();
    }
    return (string) $body;
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
