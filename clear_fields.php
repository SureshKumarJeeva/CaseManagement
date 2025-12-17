<?php

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once 'autoload.php';
$kernel = DrupalKernel::createFromRequest(Request::createFromGlobals(), $autoloader, 'prod');

$kernel->boot();

// Clear cached field definitions:
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

echo "Cleared cached field definitions.\n";