<?php

namespace Drupal\upgrade_status\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactory;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\upgrade_status\ProjectCollector;
use Drupal\upgrade_status\ScanResultFormatter;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;

class UpgradeStatusForm extends FormBase {

  /**
   * The project collector service.
   *
   * @var \Drupal\upgrade_status\ProjectCollector
   */
  protected $projectCollector;

  /**
   * Available releases store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface|mixed
   */
  protected $releaseStore;

  /**
   * The scan result formatter service.
   *
   * @var \Drupal\upgrade_status\ScanResultFormatter
   */
  protected $resultFormatter;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The module handler service.
   * 
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('upgrade_status.project_collector'),
      $container->get('keyvalue.expirable'),
      $container->get('upgrade_status.result_formatter'),
      $container->get('renderer'),
      $container->get('logger.channel.upgrade_status'),
      $container->get('module_handler')
    );
  }

  /**
   * Constructs a Drupal\upgrade_status\Form\UpgradeStatusForm.
   *
   * @param \Drupal\upgrade_status\ProjectCollector $project_collector
   *   The project collector service.
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactory $key_value_expirable
   *   The expirable key/value storage.
   * @param \Drupal\upgrade_status\ScanResultFormatter $result_formatter
   *   The scan result formatter service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Extension\ModuleHandler $module_handler
   *   The module handler.
   */
  public function __construct(
    ProjectCollector $project_collector,
    KeyValueExpirableFactory $key_value_expirable,
    ScanResultFormatter $result_formatter,
    RendererInterface $renderer,
    LoggerInterface $logger,
    ModuleHandler $module_handler
  ) {
    $this->projectCollector = $project_collector;
    $this->releaseStore = $key_value_expirable->get('update_available_releases');
    $this->resultFormatter = $result_formatter;
    $this->renderer = $renderer;
    $this->logger = $logger;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'drupal_upgrade_status_form';
  }

  /**
   * Form constructor.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'upgrade_status/upgrade_status.admin';

    // Gather project list grouped by custom and contrib projects.
    $projects = $this->projectCollector->collectProjects();

    // List custom project status first.
    $custom = ['#type' => 'markup', '#markup' => '<br /><strong>' . $this->t('No custom projects found.') . '</strong>'];
    if (count($projects['custom'])) {
      $custom = $this->buildProjectList($projects['custom']);
    }
    $form['custom'] = [
      '#type' => 'details',
      '#title' => $this->t('Custom projects'),
      '#description' => $this->t('Custom code is specific to your site, and must be upgraded manually. <a href=":upgrade">Read more about how developers can upgrade their code to Drupal 9</a>.', [':upgrade' => 'https://www.drupal.org/docs/9/how-drupal-9-is-made-and-what-is-included/how-and-why-we-deprecate-on-the-way-to-drupal-9']),
      '#open' => TRUE,
      '#attributes' => ['class' => ['upgrade-status-summary upgrade-status-summary-custom']],
      'data' => $custom,
      '#tree' => TRUE,
    ];

    // List contrib project status second.
    $contrib = ['#type' => 'markup', '#markup' => '<br /><strong>' . $this->t('No contributed projects found.') . '</strong>'];
    if (count($projects['contrib'])) {
      $contrib = $this->buildProjectList($projects['contrib'], TRUE);
    }
    $form['contrib'] = [
      '#type' => 'details',
      '#title' => $this->t('Contributed projects'),
      '#description' => $this->t('Contributed code is available from drupal.org. Problems here may be partially resolved by updating to the latest version. <a href=":update">Read more about how to update contributed projects</a>.', [':update' => 'https://www.drupal.org/docs/8/update/update-modules']),
      '#open' => TRUE,
      '#attributes' => ['class' => ['upgrade-status-summary upgrade-status-summary-contrib']],
      'data' => $contrib,
      '#tree' => TRUE,
    ];

    $form['drupal_upgrade_status_form']['action']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Scan selected'),
      '#weight' => 2,
      '#button_type' => 'primary',
    ];
    $form['drupal_upgrade_status_form']['action']['export'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export as HTML'),
      '#weight' => 5,
      '#submit' => [[$this, 'exportReportHTML']],
    ];
    $form['drupal_upgrade_status_form']['action']['export_ascii'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export as ASCII'),
      '#weight' => 6,
      '#submit' => [[$this, 'exportReportASCII']],
    ];

    return $form;
  }

  /**
   * Builds a list and status summary of projects.
   *
   * @param \Drupal\Core\Extension\Extension[] $projects
   *   Array of extensions representing projects.
   * @param bool $isContrib
   *   (Optional) Whether the list to be produced is for contributed projects.
   *
   * @return array
   *   Build array.
   */
  protected function buildProjectList(array $projects, bool $isContrib = FALSE) {
    $counters = [
      'not-scanned' => 0,
      'no-known-error' => 0,
      'known-errors' => 0,
      'known-warnings' => 0,
      'known-error-projects' => 0,
      'known-warning-projects' => 0,
    ];

    $header = ['project' => ['data' => $this->t('Project'), 'class' => 'project-label']];
    if ($isContrib) {
      $header['update'] = ['data' => $this->t('Available update'), 'class' => 'update-info'];
    }
    $header['status'] = ['data' => $this->t('Status'), 'class' => 'status-info'];

    $build['data'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#weight' => 20,
      '#options' => [],
    ];

    foreach ($projects as $name => $extension) {
      // Always use a fresh service. An injected service could get stale results
      // because scan result saving happens in different HTTP requests for most
      // cases (when analysis was successful).
      $scan_result = \Drupal::service('keyvalue')->get('upgrade_status_scan_results')->get($name);
      $info = $extension->info;
      $label = $info['name'] . (!empty($info['version']) ? ' ' . $info['version'] : '');

      $update_cell = [
        'class' => 'update-info',
        'data' => $isContrib ? $this->t('Up to date') : '',
      ];
      $label_cell = [
        'data' => [
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'label',
            '#value' => $label,
            '#attributes' => [
              'for' => 'edit-' . ($isContrib ? 'contrib' : 'custom') . '-data-data-' . str_replace('_', '-', $name),
            ],
          ],
        ],
        'class' => 'project-label',
      ];

      if ($isContrib) {
        $projectUpdateData = $this->releaseStore->get($name);

        // @todo: trigger update information fetch.
        if (!isset($projectUpdateData['releases']) || is_null($projectUpdateData['releases'])) {
          $update_cell = ['class' => 'update-info', 'data' => $this->t('N/A')];
        }
        else {
          $latestRelease = reset($projectUpdateData['releases']);
          $latestVersion = $latestRelease['version'];

          if ($info['version'] !== $latestVersion) {
            $link = $projectUpdateData['link'] . '/releases/' . $latestVersion;
            $update_cell = [
              'class' => 'update-info',
              'data' => [
                '#type' => 'link',
                '#title' => $latestVersion,
                '#url' => Url::fromUri($link),
              ]
            ];
          }
        }
      }

      // If this project was not found in our keyvalue storage, it is not yet scanned, report that.
      if (empty($scan_result)) {
        $build['data']['#options'][$name] = [
          '#attributes' => ['class' => ['not-scanned', 'project-' . $name]],
          'project' => $label_cell,
          'update' => $update_cell,
          'status' => ['class' => 'status-info', 'data' => $this->t('Not scanned')],
        ];
        $counters['not-scanned']++;
        continue;
      }

      // Unpack JSON of deprecations to display results.
      $report = json_decode($scan_result, TRUE);

      if (!empty($report['plans'])) {
        $label_cell['data']['plans'] = [
          '#type' => 'markup',
          '#markup' => '<div>' . $report['plans'] . '</div>'
        ];
      }

      if (isset($report['data']['totals'])) {
        $project_error_count = $report['data']['totals']['file_errors'];
      }
      else {
        $project_error_count = 0;
      }

      // If this project had no known issues found, report that.
      if ($project_error_count === 0) {
        $build['data']['#options'][$name] = [
          '#attributes' => ['class' => ['no-known-error', 'project-' . $name]],
          'project' => $label_cell,
          'update' => $update_cell,
          'status' => ['class' => 'status-info', 'data' => $this->t('No known errors')],
        ];
        $counters['no-known-error']++;
        continue;
      }

      // Finally this project had errors found, display them.
      $error_label = [];
      $error_class = 'known-warnings';
      if (!empty($report['data']['totals']['upgrade_status_split']['error'])) {
        $counters['known-errors'] += $report['data']['totals']['upgrade_status_split']['error'];
        $counters['known-error-projects']++;
        $error_class = 'known-errors';
        $error_label[] = $this->formatPlural(
          $report['data']['totals']['upgrade_status_split']['error'],
          '@count error',
          '@count errors'
        );
      }
      if (!empty($report['data']['totals']['upgrade_status_split']['warning'])) {
        $counters['known-warnings'] += $report['data']['totals']['upgrade_status_split']['warning'];
        $counters['known-warning-projects']++;
        $error_label[] = $this->formatPlural(
          $report['data']['totals']['upgrade_status_split']['warning'],
          '@count warning',
          '@count warnings'
        );
      }
      $build['data']['#options'][$name] = [
        '#attributes' => ['class' => [$error_class, 'project-' . $name]],
        'project' => $label_cell,
        'update' => $update_cell,
        'status' => [
          'class' => 'status-info',
          'data' => [
            '#type' => 'link',
            '#title' => join(', ', $error_label),
            '#url' => Url::fromRoute('upgrade_status.project', ['type' => $extension->getType(), 'project_machine_name' => $name]),
            '#attributes' => [
              'class' => ['use-ajax'],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => Json::encode([
                'width' => 1024,
                'height' => 568,
              ]),
            ],
          ]
        ],
      ];
    }

    if (!$isContrib) {
      // If the list is not for contrib, remove the update placeholder.
      foreach ($build['data']['#options'] as $name => &$row) {
        if (is_array($row)) {
          unset($row['update']);
        }
      }
    }

    $summary = [];

    if ($counters['known-errors'] > 0) {
      $summary[] = [
        'type' => $this->formatPlural($counters['known-errors'], '1 error', '@count errors'),
        'class' => 'error',
        'message' => $this->formatPlural($counters['known-error-projects'], 'Found in one project.', 'Found in @count projects.')
      ];
    }
    if ($counters['known-warnings'] > 0) {
      $summary[] = [
        'type' => $this->formatPlural($counters['known-warnings'], '1 warning', '@count warnings'),
        'class' => 'warning',
        'message' => $this->formatPlural($counters['known-warning-projects'], 'Found in one project.', 'Found in @count projects.')
      ];
    }
    if ($counters['no-known-error'] > 0) {
      $summary[] = [
        'type' => $this->formatPlural($counters['no-known-error'], '1 checked', '@count checked'),
        'class' => 'checked',
        'message' => $this->t('No known errors found.')
      ];
    }
    if ($counters['not-scanned'] > 0) {
      $summary[] = [
        'type' => $this->formatPlural($counters['not-scanned'], '1 not scanned', '@count not scanned'),
        'class' => 'not-scanned',
        'message' => $this->t('Scan to find errors.')
      ];
    }

    $build['summary'] = [
      '#theme' => 'upgrade_status_summary_counter',
      '#summary' => $summary
    ];

    return $build;
  }

  /**
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $operations = [];
    $projects = $this->projectCollector->collectProjects();
    $submitted = $form_state->getValues();

    // It is not possible to make an HTTP request to this same webserver
    // if the host server is PHP itself, because it is single-threaded.
    // See https://www.php.net/manual/en/features.commandline.webserver.php
    $use_http = php_sapi_name() != 'cli-server';
    // Log the selected processing method for project support purposes.
    $this->logger->notice('Processing projects without HTTP sandboxing because the built-in PHP webserver does not allow for that.');
    $php_server = !$use_http;

    // Attempt to do an HTTP request to the frontpage of this Drupal instance.
    // If that does not work then we'll not be able to process projects over
    // HTTP. Processing projects directly is less safe (in case of PHP fatal
    // errors the batch process may halt), but we have no other choice here
    // but to take a chance.
    try {
      $front = Url::fromRoute('<front>');
      $response = \Drupal::httpClient()->get($front->setAbsolute()->toString());
      if ($response->getStatusCode() != 200) {
        $use_http = FALSE;
      }
    }
    catch (\Exception $e) {
      $use_http = FALSE;
    }

    // Log the selected processing method for project support purposes.
    if (!$use_http && !$php_server) {
      $this->logger->notice('Processing projects without HTTP sandboxing because a sample HTTP request to the server failed.');
    }

    foreach (['custom', 'contrib'] as $type) {
      if (!empty($submitted[$type])) {
        foreach($submitted[$type]['data']['data'] as $project => $checked) {
          if ($checked !== 0) {
            // If the checkbox was checked, add a batch operation.
            $operations[] = [
              static::class . '::parseProject',
              [$projects[$type][$project], $use_http]
            ];
          }
        }
      }
    }
    if (!empty($operations)) {
      // Allow other modules to alter the operations to be run.
      $this->moduleHandler->alter('upgrade_status_operations', $operations, $form_state);
    }
    if (!empty($operations)) {
      $batch = [
        'title' => $this->t('Scanning projects'),
        'operations' => $operations,
      ];
      batch_set($batch);
    }
  }

  /**
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function exportReportHTML(array &$form, FormStateInterface $form_state) {
    $selected = $form_state->getValues();
    $form_state->setResponse($this->exportReport($selected, 'html'));
  }

  /**
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function exportReportASCII(array &$form, FormStateInterface $form_state) {
    $selected = $form_state->getValues();
    $form_state->setResponse($this->exportReport($selected, 'ascii'));
  }

  /**
   * Export generator.
   *
   * @param array $selected
   *   Selected projects from the form.
   * @param string $format
   *   The format of export to do: html or ascii.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Response object for this export.
   */
  public function exportReport(array $selected, string $format) {
    $extensions = [];
    $projects = $this->projectCollector->collectProjects();

    foreach (['custom', 'contrib'] as $type) {
      foreach($selected[$type]['data']['data'] as $project => $checked) {
        if ($checked !== 0) {
          // If the checkbox was checked, add it to the list.
          $extensions[$type][$project] =
            $format == 'html' ?
              $this->resultFormatter->formatResult($projects[$type][$project]) :
              $this->resultFormatter->formatAsciiResult($projects[$type][$project]);
        }
      }
    }

    $build = [
      '#theme' => 'upgrade_status_'. $format . '_export',
      '#projects' => $extensions
    ];

    $fileDate = $this->resultFormatter->formatDateTime(0, 'html_datetime');
    $extension = $format == 'html' ? '.html' : '.txt';
    $filename = 'upgrade-status-export-' . $fileDate . $extension;

    $response = new Response($this->renderer->renderRoot($build));
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    return $response;
  }

  /**
   * Batch callback to analyze a project.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *   The extension to analyze.
   * @param bool $use_http
   *   Whether to use HTTP to execute the processing or execute locally. HTTP
   *   processing could fail in some container setups. Local processing may
   *   fail due to timeout or memory limits.
   * @param array $context
   *   Batch context.
   */
  public static function parseProject(Extension $extension, $use_http, &$context) {
    $context['message'] = t('Analysis complete for @project.', ['@project' => $extension->getName()]);

    if (!$use_http) {
      \Drupal::service('upgrade_status.deprecation_analyzer')->analyze($extension);
      return;
    }

    $error = $file_name = FALSE;

    // Prepare for a POST request to scan this project. The separate HTTP
    // request is used to separate any PHP errors found from this batch process.
    // We can store any errors and gracefully continue if there was any PHP
    // errors in parsing.
    $url = Url::fromRoute(
      'upgrade_status.analyze',
      [
        'type' => $extension->getType(),
        'project_machine_name' => $extension->getName()
      ]
    );

    // Pass over authentication information because access to this functionality
    // requires administrator privileges.
    /** @var \Drupal\Core\Session\SessionConfigurationInterface $session_config */
    $session_config = \Drupal::service('session_configuration');
    $request = \Drupal::request();
    $session_options = $session_config->getOptions($request);
    // Unfortunately DrupalCI testbot does not have a domain that would normally
    // be considered valid for cookie setting, so we need to work around that
    // by manually setting the cookie domain in case there was none. What we
    // care about is we get actual results, and cookie on the host level should
    // suffice for that.
    $cookie_domain = empty($session_options['cookie_domain']) ? '.' . $request->getHost() : $session_options['cookie_domain'];
    $cookie_jar = new CookieJar();
    $cookie = new SetCookie([
      'Name' => $session_options['name'],
      'Value' => $request->cookies->get($session_options['name']),
      'Domain' => $cookie_domain,
      'Secure' => $session_options['cookie_secure'],
    ]);
    $cookie_jar->setCookie($cookie);
    $options = [
      'cookies' => $cookie_jar,
      'timeout' => 0,
    ];

    // Try a POST request with the session cookie included. We expect valid JSON
    // back. In case there was a PHP error before that, we log that.
    try {
      $response = \Drupal::httpClient()->post($url->setAbsolute()->toString(), $options);
      $data = json_decode((string) $response->getBody(), TRUE);
      if (!$data) {
        $error = (string) $response->getBody();
        $file_name = 'PHP Fatal Error';
      }
    }
    catch (\Exception $e) {
      $error = $e->getMessage();
      $file_name = 'Scanning exception';
    }

    if ($error !== FALSE) {
      /** @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface $key_value */
      $key_value = \Drupal::service('keyvalue')->get('upgrade_status_scan_results');

      $result = [];
      $result['date'] = REQUEST_TIME;
      $result['data'] = [
        'totals' => [
          'errors' => 1,
          'file_errors' => 1,
          'upgrade_status_split' => [
            'warning' => 1,
          ]
        ],
        'files' => [],
      ];
      $result['data']['files'][$file_name] = [
        'errors' => 1,
        'messages' => [
          [
            'message' => $error,
            'line' => 0,
          ],
        ],
      ];

      $key_value->set($extension->getName(), json_encode($result));
    }

  }

}
