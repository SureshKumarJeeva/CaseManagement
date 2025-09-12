<?php

namespace Drupal\Tests\xmlrpc_example\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\xmlrpc\XmlRpcTrait;

/**
 * Test case for testing the xmlrpc_example module.
 *
 * This class contains the test cases to check if module is performing as
 * expected.
 *
 * @group xmlrpc
 */
class XmlRpcExampleTest extends BrowserTestBase {

  use XmlRpcTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'xmlrpc',
    'xmlrpc_example',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $xmlRpcUrl;

  /**
   * Perform several calls to the XML-RPC interface to test the services.
   */
  public function testXmlrpcExampleBasic() {
    $this->xmlRpcUrl = $this->getEndpoint();
    // Unit test functionality.
    $result = xmlrpc($this->xmlRpcUrl, ['xmlrpc_example.add' => [3, 4]]);
    $this->assertEquals($result, 7, 'Successfully added 3+4 = 7');

    $result = xmlrpc($this->xmlRpcUrl, ['xmlrpc_example.subtract' => [4, 3]]);
    $this->assertEquals($result, 1, 'Successfully subtracted 4-3 = 1');

    // Make a multicall request.
    $options = [
      'xmlrpc_example.add' => [5, 2],
      'xmlrpc_example.subtract' => [5, 2],
    ];
    $expected = [7, 3];
    $result = xmlrpc($this->xmlRpcUrl, $options);
    $this->assertEquals($result, $expected, 'Successfully called multicall request');

    // Verify default limits.
    $result = xmlrpc($this->xmlRpcUrl, ['xmlrpc_example.subtract' => [3, 4]]);
    $this->assertEquals(xmlrpc_errno(), 10002, 'Results below minimum return custom error: 10002');

    $result = xmlrpc($this->xmlRpcUrl, ['xmlrpc_example.add' => [7, 4]]);
    $this->assertEquals(xmlrpc_errno(), 10001, 'Results beyond maximum return custom error: 10001');
  }

  /**
   * Perform several calls using XML-RPC web client.
   */
  public function testXmlrpcExampleClient() {
    // Now test the UI.
    // Add the integers.
    $edit = ['num1' => 3, 'num2' => 5];
    $this->drupalGet('/xmlrpc_example/client');
    $this->submitForm($edit, 'Add the integers');
    $this->assertSession()->pageTextContains('The XML-RPC server returned this response: 8');

    // Subtract the integers.
    $edit = ['num1' => 8, 'num2' => 3];
    $this->drupalGet('/xmlrpc_example/client');
    $this->submitForm($edit, 'Subtract the integers');
    $this->assertSession()->pageTextContains('The XML-RPC server returned this response: 5');

    // Request available methods.
    $this->drupalGet('/xmlrpc_example/client');
    $this->submitForm($edit, 'Request methods');
    // Assert that the XML-RPC Add method was found.
    $this->assertSession()->pageTextContains('xmlrpc_example.add');
    // Assert that the XML-RPC Subtract method was found.
    $this->assertSession()->pageTextContains('xmlrpc_example.subtract');

    // Before testing multicall, verify that method exists.
    $this->assertSession()->pageTextContains('system.multicall');

    // Verify multicall request.
    $edit = ['num1' => 5, 'num2' => 2];
    $this->drupalGet('/xmlrpc_example/client');
    $this->submitForm($edit, 'Add and Subtract');

    // Assert that the XML-RPC server returned the addition result.
    $this->assertSession()->pageTextContains('[0] => 7');
    // Assert that the XML-RPC server returned the subtraction result.
    $this->assertSession()->pageTextContains('[1] => 3');
  }

  /**
   * Perform several XML-RPC requests with different server settings.
   */
  public function testXmlrpcExampleServer() {
    $this->xmlRpcUrl = $this->getEndpoint();
    // Set different minimum and maximum values.
    $options = ['min' => 3, 'max' => 7];
    $this->drupalGet('/xmlrpc_example/server');
    $this->submitForm($options, 'Save configuration');

    // Results limited to >= 3 and <= 7.
    $this->assertSession()->pageTextContains('The configuration options have been saved');

    $edit = ['num1' => 8, 'num2' => 3];
    $this->drupalGet('/xmlrpc_example/client');
    $this->submitForm($edit, 'Subtract the integers');
    $this->assertSession()->pageTextContains('The XML-RPC server returned this response: 5');

    $result = xmlrpc($this->xmlRpcUrl, ['xmlrpc_example.add' => [3, 4]]);
    $this->assertEquals($result, 7, 'Successfully added 3+4 = 7');

    $result = xmlrpc($this->xmlRpcUrl, ['xmlrpc_example.subtract' => [4, 3]]);
    $this->assertEquals(xmlrpc_errno(), 10002, 'subtracting 4-3 = 1 returns custom error: 10002');

    $result = xmlrpc($this->xmlRpcUrl, ['xmlrpc_example.add' => [7, 4]]);
    $this->assertEquals(xmlrpc_errno(), 10001, 'Adding 7 + 4 = 11 returns custom error: 10001');
  }

  /**
   * Perform several XML-RPC requests.
   *
   * Alter the server behaviour with hook_xmlrpc_alter API.
   *
   * @see hook_xmlrpc_alter()
   */
  public function testXmlrpcExampleAlter() {
    $this->xmlRpcUrl = $this->getEndpoint();
    // Enable XML-RPC service altering functionality.
    $options = ['alter_enabled' => TRUE];
    $this->drupalGet('/xmlrpc_example/alter');
    $this->submitForm($options, 'Save configuration');
    // Assert that the results are not limited due to methods alteration.
    $this->assertSession()->pageTextContains('The configuration options have been saved');

    // After altering the functionality, the add and subtract methods have no
    // limits and should not return any error.
    $edit = ['num1' => 80, 'num2' => 3];
    $this->drupalGet('/xmlrpc_example/client');
    $this->submitForm($edit, 'Subtract the integers');
    $this->assertSession()->pageTextContains('The XML-RPC server returned this response: 77');

    $result = xmlrpc($this->xmlRpcUrl, ['xmlrpc_example.add' => [30, 4]]);
    $this->assertEquals($result, 34, 'Successfully added 30+4 = 34');

    $result = xmlrpc($this->xmlRpcUrl, ['xmlrpc_example.subtract' => [4, 30]]);
    $this->assertEquals($result, -26, 'Successfully subtracted 4-30 = -26');
  }

}
