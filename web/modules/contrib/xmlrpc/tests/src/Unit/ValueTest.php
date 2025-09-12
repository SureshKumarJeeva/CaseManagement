<?php

namespace Drupal\Tests\xmlrpc\Unit;

/**
 * Tests converting values to XML.
 *
 * @group xmlrpc
 */
class ValueTest extends XmlRpcUnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->includeFiles();
  }

  /**
   * @covers xmlrpc_value_get_xml
   *
   * @dataProvider valueGetXmlProvider
   */
  public function testValueGetXml($expected_xml, $value) {
    $xmlrpc_value = xmlrpc_value($value);
    $xml = xmlrpc_value_get_xml($xmlrpc_value);
    $this->assertXmlStringEqualsXmlString($expected_xml, $xml);
  }

  /**
   * Data provider for testValueGetXml().
   */
  public function valueGetXmlProvider() {
    $this->includeFiles();

    return [
      // String.
      [
        '<string>Foo</string>',
        'Foo',
      ],
      // Boolean true.
      [
        '<boolean>1</boolean>',
        TRUE,
      ],
      // Boolean false.
      [
        '<boolean>0</boolean>',
        FALSE,
      ],
      // Integer.
      [
        '<int>42</int>',
        42,
      ],
      // Double.
      [
        '<double>3.14</double>',
        3.14,
      ],
      // Null.
      [
        '<nil />',
        NULL,
      ],
      // Array.
      [
        '<array><data><value><string>Foo</string></value></data></array>',
        ['Foo'],
      ],
      // Struct.
      [
        '<struct><member><name>Foo</name><value><string>Bar</string></value></member></struct>',
        [
          'Foo' => 'Bar',
        ],
      ],
      // Date.
      [
        '<dateTime.iso8601>20090903T00:12:00</dateTime.iso8601>',
        xmlrpc_date(1251936720),
      ],
      // Base 64.
      [
        '<base64>Um05dg==</base64>',
        xmlrpc_base64(base64_encode('Foo')),
      ],
    ];
  }

}
