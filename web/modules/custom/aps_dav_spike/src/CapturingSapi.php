<?php

namespace Drupal\aps_dav_spike;

use Sabre\HTTP\ResponseInterface;
use Sabre\HTTP\Sapi;

/**
 * A sabre SAPI that captures the response instead of writing it to output.
 *
 * Sabre normally calls header() and streams the body straight to php://output.
 * Inside a middleware that is wrong twice over: Symfony sends its own status
 * line and headers first, so every header() call from sabre would arrive after
 * output has started and be silently discarded — including the DAV header this
 * whole spike exists to emit.
 *
 * Swapping the SAPI keeps the response as an object, so the middleware can
 * translate it into a Symfony Response and let Drupal own the response
 * lifecycle. The alternative — exec() then exit() — works, but skips kernel
 * termination and cannot be composed with anything.
 *
 * sendResponse() is static in the parent, so this override must be static too.
 * PHP resolves $sapi->sendResponse() against the instance's class, so assigning
 * an instance of this class to Server::$sapi is enough to redirect it here.
 */
class CapturingSapi extends Sapi {

  /**
   * The response sabre produced, or NULL if it produced none.
   *
   * @var \Sabre\HTTP\ResponseInterface|null
   */
  public static $captured = NULL;

  /**
   * {@inheritdoc}
   */
  public static function sendResponse(ResponseInterface $response) {
    self::$captured = $response;
  }

}
