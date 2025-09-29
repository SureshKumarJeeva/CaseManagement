<?php

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Database\Database;

$autoloader = require_once __DIR__ . '/vendor/autoload.php';

$site_path = __DIR__ . '/web/sites/default';

// The app root is your Drupal root (where index.php lives, e.g., 'web')
$app_root = __DIR__ . '/web';

// Create kernel
$kernel = new DrupalKernel('prod', $autoloader, TRUE, $app_root);

$kernel->setSitePath($site_path);
// Create a request
$request = Request::createFromGlobals();

// Boot the kernel
$kernel->boot();
// Initialize database via the service container
$container = $kernel->getContainer();
$database = $container->get('database');

echo $database->getConnection()->getProvider();
// Force the active DB connection
//Database::getConnection()->setActiveConnection('default');

// Show the provider
//echo Database::getConnection()->getProvider();