<?php

if (file_exists(dirname(__FILE__) . '/settings.local.php')) {
  $options['uri'] = 'https://localhost';
}

if (file_exists(dirname(__FILE__) . '/settings.dev.php')) {
  $options['uri'] = 'https://associatedps.dresden.solutions';
}

if (file_exists(dirname(__FILE__) . '/settings.prod.php')) {
  $options['uri'] = 'https://app.associatedps.com.au';
}
