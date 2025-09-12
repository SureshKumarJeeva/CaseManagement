<?php

namespace Drupal\upgrade_status;

use Composer\Semver\Semver;
use Drupal\Core\Extension\Extension;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Template\TwigEnvironment;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

final class DeprecationAnalyzer {

  /**
   * The oldest supported core minor version.
   *
   * @var string
   */
  const CORE_MINOR_OLDEST_SUPPORTED = '8.7';

  /**
   * Upgrade status scan result storage.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected $scanResultStorage;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Path to the PHPStan neon configuration.
   *
   * @var string
   */
  protected $phpstanNeonPath;

  /**
   * Path to the vendor directory.
   *
   * @var string
   */
  protected $vendorPath;

  /**
   * Temporary directory to use for running phpstan.
   * 
   * @var string
   */
  protected $temporaryDirectory;

  /**
   * HTTP Client for drupal.org API calls.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * File system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The Twig environment.
   *
   * @var \Drupal\Core\Template\TwigEnvironment
   */
  protected $twigEnvironment;

  /**
   * The library deprecation analyzer.
   *
   * @var \Drupal\upgrade_status\LibraryDeprecationAnalyzer
   */
  protected $libraryDeprecationAnalyzer;

  /**
   * Constructs a deprecation analyzer.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value_factory
   *   The key/value factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \GuzzleHttp\Client $http_client
   *   HTTP client.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   File system service.
   * @param \Drupal\Core\Template\TwigEnvironment $twig_environment
   *   The Twig environment.
   * @param \Drupal\upgrade_status\LibraryDeprecationAnalyzer
   *   The library deprecation analyzer.
   */
  public function __construct(
    KeyValueFactoryInterface $key_value_factory,
    LoggerInterface $logger,
    Client $http_client,
    FileSystemInterface $file_system,
    TwigEnvironment $twig_environment,
    LibraryDeprecationAnalyzer $library_deprecation_analyzer
  ) {
    $this->scanResultStorage = $key_value_factory->get('upgrade_status_scan_results');
    // Log errors to an upgrade status logger channel.
    $this->logger = $logger;
    $this->httpClient = $http_client;
    $this->fileSystem = $file_system;
    $this->twigEnvironment = $twig_environment;
    $this->libraryDeprecationAnalyzer = $library_deprecation_analyzer;

    $this->vendorPath = $this->findVendorPath();

    $this->temporaryDirectory = file_directory_temp() . '/upgrade_status';
    if (!file_exists($this->temporaryDirectory)) {
      $this->prepareTempDirectory();
    }

    $this->phpstanNeonPath = $this->temporaryDirectory . '/deprecation_testing.neon';
    $this->createModifiedNeonFile();
  }

  /**
   * Finds vendor location.
   *
   * @return string|null
   *   Vendor directory path if found, null otherwise.
   */
  protected function findVendorPath() {
    // The vendor directory may be found inside the webroot (unlikely).
    if (file_exists(DRUPAL_ROOT . '/vendor/bin/phpstan')) {
      return DRUPAL_ROOT . '/vendor';
    }
    // Most likely the vendor directory is found alongside the webroot.
    elseif (file_exists(dirname(DRUPAL_ROOT) . '/vendor/bin/phpstan')) {
      return dirname(DRUPAL_ROOT) . '/vendor';
    }
    // One of the above should have worked.
    $this->logger->error('PHPStan executable not found.');
  }

  /**
   * Analyze the codebase of an extension including all its sub-components.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *   The extension to analyze.
   *
   * @return null
   *   Errors are logged to the logger, data is stored to keyvalue storage.
   */
  public function analyze(Extension $extension) {
    $project_dir = DRUPAL_ROOT . '/' . $extension->subpath;
    $this->logger->notice('Processing %path.', ['%path' => $project_dir]);

    $output = [];
    exec($this->vendorPath . '/bin/phpstan analyse --error-format=json -c ' . $this->phpstanNeonPath . ' ' . $project_dir, $output);
    $json = json_decode(join('', $output), TRUE);
    $result = ['date' => REQUEST_TIME, 'data' => $json];

    $twig_deprecations = $this->analyzeTwigTemplates($extension->subpath);
    foreach ($twig_deprecations as $twig_deprecation) {
      preg_match('/\s([a-zA-Z0-9\_\-\/]+.html\.twig)\s/', $twig_deprecation, $file_matches);
      preg_match('/\s(\d).?$/', $twig_deprecation, $line_matches);
      $result['data']['files'][$file_matches[1]]['messages'] = [
        [
          'message' => $twig_deprecation,
          'line' => $line_matches[1] ?: 0,
        ],
      ];
      $result['data']['totals']['errors']++;
      $result['data']['totals']['file_errors']++;
    }

    $deprecation_messages = $this->libraryDeprecationAnalyzer->analyze($extension);
    foreach ($deprecation_messages as $deprecation_message) {
      $result['data']['files'][$deprecation_message->getFile()]['messages'][] = [
        'message' => $deprecation_message->getMessage(),
        'line' => $deprecation_message->getLine(),
      ];
      $result['data']['totals']['errors']++;
      $result['data']['totals']['file_errors']++;
    }

    // Manually add on info file incompatibility to phpstan results.
    $info = $extension->info;
    if (!isset($info['core_version_requirement'])) {
      $result['data']['files'][$extension->subpath . '/' . $extension->getFilename()]['messages'][] = [
        'message' => 'Add <code>core_version_requirement: ^8 || ^9</code> to ' . $extension->getFilename() . ' to designate that the module is compatible with Drupal 9. See https://www.drupal.org/node/3070687.',
        'line' => 0,
      ];
      $result['data']['totals']['errors']++;
      $result['data']['totals']['file_errors']++;
    }
    elseif (!Semver::satisfies('9.0.0', $info['core_version_requirement'])) {
      $result['data']['files'][$extension->subpath . '/' . $extension->getFilename()]['messages'][] = [
        'message' => "The current value  <code>core_version_requirement: {$info['core_version_requirement']}</code> in {$extension->getFilename()} is not compatible with Drupal 9.0.0. See https://www.drupal.org/node/3070687.",
        'line' => 0,
      ];
      $result['data']['totals']['errors']++;
      $result['data']['totals']['file_errors']++;
    }

    foreach($result['data']['files'] as $path => &$errors) {
      if (!empty($errors['messages'])) {
        foreach($errors['messages'] as &$error) {

          // Overwrite message with processed text. Save category.
          list($message, $category) = $this->categorizeMessage($error['message'], $extension);
          $error['message'] = $message;
          $error['upgrade_status_category'] = $category;

          // Sum up the error based on the category it ended up in. Split the
          // categories into two high level buckets needing attention now or
          // later for Drupal 9 compatibility. Ignore Drupal 10 here.
          @$result['data']['totals']['upgrade_status_category'][$category]++;
          if (in_array($category, ['safe', 'old'])) {
            @$result['data']['totals']['upgrade_status_split']['error']++;
          }
          elseif (in_array($category, ['later', 'uncategorized'])) {
            @$result['data']['totals']['upgrade_status_split']['warning']++;
          }
        }
      }
    }

    // For contributed projects, attempt to grab Drupal 9 plan information.
    if (!empty($extension->info['project'])) {
      /** @var \Psr\Http\Message\ResponseInterface $response */
      $response = $this->httpClient->request('GET', 'https://www.drupal.org/api-d7/node.json?field_project_machine_name=' . $extension->getName());
      if ($response->getStatusCode()) {
        $data = json_decode($response->getBody(), TRUE);
        if (!empty($data['list'][0]['field_next_major_version_info']['value'])) {
          $result['plans'] = str_replace('href="/', 'href="https://drupal.org/', $data['list'][0]['field_next_major_version_info']['value']);
          // @todo implement "replaced by" collection once drupal.org exposes
          // that in an accessible way
          // @todo once/if drupal.org deprecation testing is in place, grab
          // the status from there so we know if it improves by updating
        }
      }
    }

    // Store the analysis results in our storage bin.
    $this->scanResultStorage->set($extension->getName(), json_encode($result));
  }

  /**
   * Analyzes twig templates for calls of deprecated code.
   *
   * @param $directory
   *   The directory which Twig templates should be analyzed.
   *
   * @return array
   */
  protected function analyzeTwigTemplates($directory) {
    return (new \Twig_Util_DeprecationCollector($this->twigEnvironment))->collectDir($directory, '.html.twig');
  }

  /**
   * Prepare temporary directories for Upgrade Status.
   *
   * The created directories in Drupal's temporary directory are needed to
   * dynamically set a temporary directory for PHPStan's cache in the neon file
   * provided by Upgrade Status.
   *
   * @return bool
   *   True if the temporary directory is created, false if not.
   */
  protected function prepareTempDirectory() {
    $success = $this->fileSystem->prepareDirectory($this->temporaryDirectory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    if (!$success) {
      $this->logger->error('Unable to create temporary directory for Upgrade Status: %directory.', ['%directory' => $this->temporaryDirectory]);
      return $success;
    }

    $phpstan_cache_directory = $this->temporaryDirectory . '/phpstan';
    $success = $this->fileSystem->prepareDirectory($phpstan_cache_directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    if (!$success) {
      $this->logger->error('Unable to create temporary directory for PHPStan: %directory.', ['%directory' => $phpstan_cache_directory]);
    }

    return $success;
  }

  /**
   * Creates the final config file in the temporary directory.
   *
   * @return bool
   */
  protected function createModifiedNeonFile() {
    $module_path = DRUPAL_ROOT . '/' . drupal_get_path('module', 'upgrade_status');
    $config = file_get_contents($module_path . '/deprecation_testing.neon');
    $config = str_replace(
      'parameters:',
      "parameters:\n\ttmpDir: '" . $this->temporaryDirectory . '/phpstan' . "'\n" .
        "\tdrupal:\n\t\tdrupal_root: '" . DRUPAL_ROOT . "'",
      $config
    );
    $config .= "\nincludes:\n\t- '" .
      $this->vendorPath . "/mglaman/phpstan-drupal/extension.neon'\n\t- '" .
      $this->vendorPath . "/phpstan/phpstan-deprecation-rules/rules.neon'\n";
    $success = file_put_contents($this->phpstanNeonPath, $config);
    if (!$success) {
      $this->logger->error('Unable to write configuration for PHPStan: %file.', ['%file' => $this->phpstanNeonPath]);
    }
    return $success ? TRUE : FALSE;
  }

  /**
   * Annotate and categorize the error message.
   *
   * @param string $error
   *   Error message as identified by phpstan.
   * @param \Drupal\Core\Extension\Extension $extension
   *   Extension where the error was found.
   *
   * @return array
   *   Two item array. The reformatted error and the category.
   */
  protected function categorizeMessage(string $error, Extension $extension) {
    // Make the error more readable in case it has the deprecation text.
    $error = preg_replace('!:\s+(in|as of)!', '. Deprecated \1', $error);

    // TestBase and WebTestBase replacements are available at least from Drupal
    // 8.6.0, so use that version number. Otherwise use the number from the
    // message.
    $version = '';
    if (preg_match('!\\\\(Web|)TestBase. Deprecated in [Dd]rupal[ :]8.8.0 !', $error)) {
      $version = '8.6.0';
      $error .= " Replacement available from drupal:8.6.0.";
    }
    elseif (preg_match('!Deprecated (in|as of) [Dd]rupal[ :](8.\d)!', $error, $version_found)) {
      $version = $version_found[2];
    }

    // Set a default category for the messages we can't categorize.
    $category = 'uncategorized';

    if (!empty($version)) {

      // Categorize deprecations for contributed projects based on
      // community rules.
      if (!empty($extension->info['project'])) {
        // If the found deprecation is older or equal to the oldest
        // supported core version, it should be old enough to update
        // either way.
        if (version_compare($version, self::CORE_MINOR_OLDEST_SUPPORTED) <= 0) {
          $category = 'old';
        }
        // If the deprecation is not old and we are dealing with a contrib
        // module, the deprecation should be dealt with later.
        else {
          $category = 'later';
        }
      }
      // For custom projects, look at this site's version specifically.
      else {
        // If the found deprecation is older or equal to the current
        // Drupal version on this site, it should be safe to update.
        if (version_compare($version, \Drupal::VERSION) <= 0) {
          $category = 'safe';
        }
        else {
          $category = 'later';
        }
      }
    }

    // If the deprecation is already for Drupal 10, put it in the ignore
    // category. This overwrites any categorization before intentionally.
    if (preg_match('!(will be|is) removed (before|from) [Dd]rupal[ :](10.\d)!', $error)) {
      $category = 'ignore';
    }

    return [$error, $category];
  }

}
