<?php

/**
 * @file
 * Page callback file for the xmlrpc module.
 */

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Parameter positions for XML-RPC callback definitions.
 *
 * @var int
 *
 * @see hook_xmlrpc()
 */
const XMLRPC_SERVER_METHOD_PARAM = 0;
const XMLRPC_SERVER_CALLBACK_PARAM = 1;
const XMLRPC_SERVER_SIGNATURE_PARAM = 2;
const XMLRPC_SERVER_HELP_PARAM = 3;

/**
 * XML-RPC error codes.
 *
 * @var int
 */
const XMLRPC_SYNTAX_ERROR = -32700;
const XMLRPC_ENCODING_ERROR = -32701;
const XMLRPC_INVALID_CHARACTER = -32702;
const XMLRPC_SPEC_ERROR = -32600;
const XMLRPC_METHOD_NOT_FOUND = -32601;
const XMLRPC_INVALID_ARGUMENT = -32602;
const XMLRPC_INTERNAL_ERROR = -32603;
const XMLRPC_APP_ERROR = -32500;
const XMLRPC_SYSTEM_ERROR = -32400;
const XMLRPC_TRANSPORT_ERROR = -32300;

/**
 * Invokes XML-RPC methods on this server.
 *
 * @param array $callbacks
 *   Either an associative array of external XML-RPC method names as keys with
 *   the callbacks they map to as values, or a more complex structure
 *   describing XML-RPC callbacks as returned from hook_xmlrpc().
 *
 * @return \Symfony\Component\HttpFoundation\Response
 *   A Response object.
 */
function xmlrpc_server(array $callbacks) {
  $xmlrpc_server = new stdClass();
  // Define built-in XML-RPC method names.
  $defaults = [
    'system.multicall' => 'xmlrpc_server_multicall',
    [
      'system.methodSignature',
      'xmlrpc_server_method_signature',
      ['array', 'string'],
      'Returns an array describing the return type and required parameters of a method.',
    ],
    [
      'system.getCapabilities',
      'xmlrpc_server_get_capabilities',
      ['struct'],
      'Returns a struct describing the XML-RPC specifications supported by this server.',
    ],
    [
      'system.listMethods',
      'xmlrpc_server_list_methods',
      ['array'],
      'Returns an array of available methods on this server.',
    ],
    [
      'system.methodHelp',
      'xmlrpc_server_method_help',
      ['string', 'string'],
      'Returns a documentation string for the specified method.',
    ],
  ];
  // We build an array of all method names by combining the built-ins
  // with those defined by modules implementing the _xmlrpc hook.
  // Built-in methods are overridable.
  $callbacks = array_merge($defaults, (array) $callbacks);
  \Drupal::moduleHandler()->alter('xmlrpc', $callbacks);
  foreach ($callbacks as $key => $callback) {
    if (is_int($key) && is_array($callback)) {
      // The XML-RPC method is expected to be defined as follows:
      // @code
      // [
      //   'foo.methodName',
      //   'foo_callback',
      //   ['array', 'string'],
      //   'Help message to describe the method.',
      // ]
      // @endcode
      // At least two parameters are required: method name and callback.
      if (!isset($callback[XMLRPC_SERVER_METHOD_PARAM]) || !isset($callback[XMLRPC_SERVER_CALLBACK_PARAM])) {
        \Drupal::logger('xmlrpc')->warning('Wrongly defined XML-RPC method detected. Check implementations of hook_xmlrpc() and hook_xmlrpc_alter() to see which one is wrong.');
        continue;
      }
    }
    elseif (is_string($key)) {
      // The XML-RPC method is defined as method name => callback function.
      $callback = [$key, $callback];
    }
    else {
      // The XML-RPC method definition is in a format that is not supported.
      \Drupal::logger('xmlrpc')->warning('Wrongly defined XML-RPC method detected. Check implementations of hook_xmlrpc() and hook_xmlrpc_alter() to see which one is wrong.');
      continue;
    }

    // Signature and help are optional parameters for the XML-RPC method
    // definition, so add default values for these.
    $callback += [
      XMLRPC_SERVER_SIGNATURE_PARAM => 'undef',
      XMLRPC_SERVER_HELP_PARAM => '',
    ];

    $method = $callback[XMLRPC_SERVER_METHOD_PARAM];
    $xmlrpc_server->callbacks[$method] = $callback[XMLRPC_SERVER_CALLBACK_PARAM];
    $xmlrpc_server->signatures[$method] = $callback[XMLRPC_SERVER_SIGNATURE_PARAM];
    $xmlrpc_server->help[$method] = $callback[XMLRPC_SERVER_HELP_PARAM];
  }

  $data = file_get_contents('php://input');
  if (!$data) {
    throw new BadRequestHttpException('XML-RPC server accepts POST requests only.');
  }
  $xmlrpc_server->message = xmlrpc_message($data);
  if (!xmlrpc_message_parse($xmlrpc_server->message)) {
    return xmlrpc_server_error(XMLRPC_SYNTAX_ERROR, t('Parse error. Request not well formed.'));
  }
  if ($xmlrpc_server->message->messagetype != 'methodCall') {
    return xmlrpc_server_error(XMLRPC_SPEC_ERROR, t('Server error. Invalid XML-RPC. Request must be a methodCall.'));
  }
  if (!isset($xmlrpc_server->message->params)) {
    $xmlrpc_server->message->params = [];
  }
  xmlrpc_server_set($xmlrpc_server);
  $result = xmlrpc_server_call($xmlrpc_server, $xmlrpc_server->message->methodname, $xmlrpc_server->message->params);

  if (is_object($result) && !empty($result->is_error)) {
    return xmlrpc_server_error($result);
  }
  // Encode the result.
  $r = xmlrpc_value($result);
  // Create the XML.
  $xml = '
<methodResponse>
  <params>
  <param>
    <value>' . xmlrpc_value_get_xml($r) . '</value>
  </param>
  </params>
</methodResponse>

';
  // Send it.
  return xmlrpc_server_output($xml);
}

/**
 * Throws an XML-RPC error.
 *
 * @param int|object $error
 *   An error object or integer error code.
 * @param false|string $message
 *   (optional) The description of the error. Used only if an integer error
 *   code was passed in.
 *
 * @return \Symfony\Component\HttpFoundation\Response
 *   A Response object.
 */
function xmlrpc_server_error($error, $message = FALSE) {
  if ($message && !is_object($error)) {
    $error = xmlrpc_error($error, $message);
  }
  return xmlrpc_server_output(xmlrpc_error_get_xml($error));
}

/**
 * Sends XML-RPC output to the browser.
 *
 * @param string $xml
 *   XML to send to the browser.
 *
 * @return \Symfony\Component\HttpFoundation\Response
 *   A Response object.
 */
function xmlrpc_server_output($xml) {
  $xml = '<?xml version="1.0" encoding="utf-8" ?>' . "\n" . $xml;
  $headers = [
    'Content-Length' => strlen($xml),
    'Content-Type' => 'text/xml; charset=utf-8',
  ];
  return new Response($xml, 200, $headers);
}

/**
 * Stores a copy of an XML-RPC request temporarily.
 *
 * @param null|object $xmlrpc_server
 *   (optional) Request object created by xmlrpc_server(). Omit to leave the
 *   previous server object saved.
 *
 * @return null|object
 *   The latest stored request.
 *
 * @see xmlrpc_server_get()
 */
function xmlrpc_server_set($xmlrpc_server = NULL) {
  static $server;
  if (!isset($server)) {
    $server = $xmlrpc_server;
  }
  return $server;
}

/**
 * Retrieves the latest stored XML-RPC request.
 *
 * @return object
 *   The stored request.
 *
 * @see xmlrpc_server_set()
 */
function xmlrpc_server_get() {
  return xmlrpc_server_set();
}

/**
 * Dispatches an XML-RPC request and any parameters to the appropriate handler.
 *
 * @param object $xmlrpc_server
 *   Object containing information about this XML-RPC server, the methods it
 *   provides, their signatures, etc.
 * @param string $method_name
 *   The external XML-RPC method name; e.g., 'system.methodHelp'.
 * @param array $args
 *   Array containing any parameters that are to be sent along with the request.
 *
 * @return mixed
 *   The results of the call.
 */
function xmlrpc_server_call($xmlrpc_server, $method_name, array $args) {
  // Make sure parameters are in an array.
  if ($args && !is_array($args)) {
    $args = [$args];
  }
  // Has this method been mapped to a Drupal function by us or by modules?
  if (!isset($xmlrpc_server->callbacks[$method_name])) {
    return xmlrpc_error(XMLRPC_METHOD_NOT_FOUND, t('Server error. Requested method @method_name not specified.', [
      "@method_name" => $xmlrpc_server->message->methodname,
    ]));
  }
  $method = $xmlrpc_server->callbacks[$method_name];
  $signature = $xmlrpc_server->signatures[$method_name];

  // If the method has a signature, validate the request against the signature.
  if (is_array($signature)) {
    $ok = TRUE;
    // Remove first element of $signature which is the unused 'return type'.
    array_shift($signature);
    // Check the number of arguments.
    if (count($args) != count($signature)) {
      return xmlrpc_error(XMLRPC_INVALID_ARGUMENT, t('Server error. Wrong number of method parameters.'));
    }
    // Check the argument types.
    foreach ($signature as $key => $type) {
      $arg = $args[$key];
      switch ($type) {
        case 'int':
        case 'i4':
          if (is_array($arg) || !is_int($arg)) {
            $ok = FALSE;
          }
          break;

        case 'base64':
        case 'string':
          if (!is_string($arg)) {
            $ok = FALSE;
          }
          break;

        case 'boolean':
          if ($arg !== FALSE && $arg !== TRUE) {
            $ok = FALSE;
          }
          break;

        case 'float':
        case 'double':
          if (!is_float($arg)) {
            $ok = FALSE;
          }
          break;

        case 'date':
        case 'dateTime.iso8601':
          if (!$arg->is_date) {
            $ok = FALSE;
          }
          break;
      }
      if (!$ok) {
        return xmlrpc_error(XMLRPC_INVALID_ARGUMENT, t('Server error. Invalid method parameters.'));
      }
    }
  }

  if (!is_callable($method)) {
    return xmlrpc_error(XMLRPC_METHOD_NOT_FOUND, t('Server error. Requested function @method does not exist.', ["@method" => $method]));
  }
  // Call the mapped function.
  return call_user_func_array($method, $args);
}

/**
 * Dispatches multiple XML-RPC requests.
 *
 * @param array $method_calls
 *   An array of XML-RPC requests to make. Each request is an array with the
 *   following elements:
 *   - methodName: Name of the method to invoke.
 *   - params: Parameters to pass to the method.
 *
 * @return array
 *   An array of the results of each request.
 *
 * @see xmlrpc_server_call()
 */
function xmlrpc_server_multicall(array $method_calls) {
  // See http://www.xmlrpc.com/discuss/msgReader$1208
  $return = [];
  $xmlrpc_server = xmlrpc_server_get();
  foreach ($method_calls as $call) {
    $ok = TRUE;
    if (!isset($call['methodName']) || !isset($call['params'])) {
      $result = xmlrpc_error(3, t('Invalid syntax for system.multicall.'));
      $ok = FALSE;
    }
    $method = $call['methodName'];
    $params = $call['params'];
    if ($method == 'system.multicall') {
      $result = xmlrpc_error(XMLRPC_SPEC_ERROR, t('Recursive calls to system.multicall are forbidden.'));
    }
    elseif ($ok) {
      $result = xmlrpc_server_call($xmlrpc_server, $method, $params);
    }
    if (is_object($result) && !empty($result->is_error)) {
      $return[] = [
        'faultCode' => $result->code,
        'faultString' => $result->message,
      ];
    }
    else {
      $return[] = [$result];
    }
  }
  return $return;
}

/**
 * Lists the methods available on this XML-RPC server.
 *
 * XML-RPC method system.listMethods maps to this function.
 *
 * @return array
 *   Array of the names of methods available on this server.
 */
function xmlrpc_server_list_methods() {
  $xmlrpc_server = xmlrpc_server_get();
  return array_keys($xmlrpc_server->callbacks);
}

/**
 * Returns a list of the capabilities of this server.
 *
 * XML-RPC method system.getCapabilities maps to this function.
 *
 * @return array
 *   Array of server capabilities.
 *
 * @see http://groups.yahoo.com/group/xml-rpc/message/2897
 */
function xmlrpc_server_get_capabilities() {
  return [
    'xmlrpc' => [
      'specUrl' => 'http://www.xmlrpc.com/spec',
      'specVersion' => 1,
    ],
    'faults_interop' => [
      'specUrl' => 'http://xmlrpc-epi.sourceforge.net/specs/rfc.fault_codes.php',
      'specVersion' => 20010516,
    ],
    'system.multicall' => [
      'specUrl' => 'http://www.xmlrpc.com/discuss/msgReader$1208',
      'specVersion' => 1,
    ],
    'introspection' => [
      'specUrl' => 'http://scripts.incutio.com/xmlrpc/introspection.html',
      'specVersion' => 1,
    ],
  ];
}

/**
 * Returns one method signature for a function.
 *
 * This is the function mapped to the XML-RPC method system.methodSignature.
 *
 * A method signature is an array of the input and output types of a method. For
 * instance, the method signature of this function is array('array', 'string'),
 * because it takes an array and returns a string.
 *
 * @param string $methodname
 *   Name of method to return a method signature for.
 *
 * @return array|object
 *   An array of arrays of types, each of the arrays representing one method
 *   signature of the function that $methodname maps to. Or an error object.
 */
function xmlrpc_server_method_signature($methodname) {
  $xmlrpc_server = xmlrpc_server_get();
  if (!isset($xmlrpc_server->callbacks[$methodname])) {
    return xmlrpc_error(XMLRPC_METHOD_NOT_FOUND, t('Server error. Requested method @methodname not specified.', ["@methodname" => $methodname]));
  }
  if ($xmlrpc_server->signatures[$methodname] == 'undef') {
    return 'undef';
  }
  if (!is_array($xmlrpc_server->signatures[$methodname])) {
    return xmlrpc_error(XMLRPC_INVALID_ARGUMENT, t('Server error. Requested method @methodname signature not correctly specified.', ["@methodname" => $methodname]));
  }
  // We array of types.
  $return = [];
  foreach ($xmlrpc_server->signatures[$methodname] as $type) {
    $return[] = $type;
  }
  return [$return];
}

/**
 * Returns the help for an XML-RPC method.
 *
 * XML-RPC method system.methodHelp maps to this function.
 *
 * @param string $method
 *   Name of method for which we return a help string.
 *
 * @return string
 *   Help text for $method.
 */
function xmlrpc_server_method_help($method) {
  $xmlrpc_server = xmlrpc_server_get();
  return $xmlrpc_server->help[$method];
}
