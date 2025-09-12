<?php

namespace Drupal\Tests\xmlrpc\Functional;

use GuzzleHttp\Exception\ClientException;

/**
 * Perform basic XML-RPC tests that do not require addition callbacks.
 *
 * @group xmlrpc
 */
class XmlRpcBasicTest extends XmlRpcTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['xmlrpc'];

  /**
   * Ensure that a basic XML-RPC call with no parameters works.
   */
  public function testListMethods() {
    // Minimum list of methods that should be included.
    $minimum = [
      'system.multicall',
      'system.methodSignature',
      'system.getCapabilities',
      'system.listMethods',
      'system.methodHelp',
    ];

    // Invoke XML-RPC call to get list of methods.
    $methods = $this->xmlRpcGet(['system.listMethods' => []]);

    // Ensure that the minimum methods were found.
    $count = 0;
    foreach ((array) $methods as $method) {
      if (in_array($method, $minimum)) {
        $count++;
      }
    }

    $this->assertEquals($count, count($minimum), 'system.listMethods returned at least the minimum listing');
  }

  /**
   * Ensure that system.methodSignature returns an array of signatures.
   */
  public function testMethodSignature() {
    $signature = $this->xmlRpcGet(['system.methodSignature' => ['system.listMethods']]);
    $this->assertTrue(is_array($signature) && !empty($signature) && is_array($signature[0]), 'system.methodSignature returns an array of signature arrays.');
  }

  /**
   * Tests getting signature from a method that doesn't have one.
   *
   * @param string $method
   *   The method to check the signature of.
   *
   * @dataProvider noSignatureDataProvider
   */
  public function testMethodSignatureOnMethodWithoutSignature(string $method) {
    // Install xmlrpc__test module.
    $this->container->get('module_installer')->install(['xmlrpc_test']);
    $signature = $this->xmlRpcGet(['system.methodSignature' => [$method]]);
    $this->assertSame('undef', $signature);
  }

  /**
   * Data provider for ::testMethodSignatureOnMethodWithoutSignature().
   */
  public function noSignatureDataProvider(): array {
    return [
      ['test.noSignatureSimple'],
      ['test.noSignatureComplex'],
      ['test.classCallback'],
    ];
  }

  /**
   * Ensure that XML-RPC correctly handles invalid messages when parsing.
   */
  public function testInvalidMessageParsing() {
    module_load_include('inc', 'xmlrpc');
    $invalid_messages = [
      [
        'message' => xmlrpc_message(''),
        'assertion' => 'Empty message correctly rejected during parsing.',
      ],
      [
        'message' => xmlrpc_message('<?xml version="1.0" encoding="ISO-8859-1"?>'),
        'assertion' => 'Empty message with XML declaration correctly rejected during parsing.',
      ],
      [
        'message' => xmlrpc_message('<?xml version="1.0"?><params><param><value><string>value</string></value></param></params>'),
        'assertion' => 'Non-empty message without a valid message type is rejected during parsing.',
      ],
      [
        'message' => xmlrpc_message('<methodResponse><params><param><value><string>value</string></value></param></methodResponse>'),
        'assertion' => 'Non-empty malformed message is rejected during parsing.',
      ],
    ];

    foreach ($invalid_messages as $assertion) {
      $this->assertFalse(xmlrpc_message_parse($assertion['message']), $assertion['assertion']);
    }
  }

  /**
   * Ensure that XML-RPC correctly handles XML Accept headers.
   */
  public function testAcceptHeaders() {
    $request_header_sets = [
      // Default.
      'implicit' => [],
      'text/xml' => ['Accept' => 'text/xml'],
      'application/xml' => ['Accept' => 'application/xml'],
    ];

    foreach ($request_header_sets as $accept => $headers) {
      try {
        $methods = $this->xmlRpcGet(['system.listMethods' => []], $headers);
        $this->assertTrue(is_array($methods), strtr('@accept accept header is accepted', [
          '@accept' => $accept,
        ]));
      }
      catch (ClientException $e) {
        $this->fail($e);
      }
    }
  }

  /**
   * Addresses bug https://www.drupal.org/node/2146833.
   *
   * @link http://xmlrpc-epi.sourceforge.net/specs/rfc.fault_codes.php
   */
  public function testInvalidServer() {
    $invalid_endpoint = 'http://example.invalid/xmlrpc';
    $result = xmlrpc($invalid_endpoint, ['system.listMethods' => []]);
    $this->verboseResult($result);
    $this->assertFalse($result, "Calling an unknown host returns an error condition");

    $this->assertEquals(-32300, xmlrpc_errno(), "Calling an unknown host is reported as a transport error.");
    $message = xmlrpc_error_msg();
    $this->assertFalse(empty($message), "Calling an unknown host returns a meaningful error message.");
  }

}
