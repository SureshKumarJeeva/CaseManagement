<?php

namespace Drupal\Tests\xmlrpc\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Base class for XML-RPC unit tests.
 */
abstract class XmlRpcUnitTestCase extends UnitTestCase {

  /**
   * Returns the absolute directory path of the XML-RPC module.
   *
   * @return string
   *   The absolute path to the XML-RPC module.
   */
  protected function absolutePath(): string {
    return dirname(dirname(dirname(__DIR__)));
  }

  /**
   * Includes files xmlrpc.inc and xmlrpc.server.inc.
   */
  protected function includeFiles() {
    $module_path = $this->absolutePath();
    require_once $module_path . '/xmlrpc.inc';
    require_once $module_path . '/xmlrpc.server.inc';
  }

}
