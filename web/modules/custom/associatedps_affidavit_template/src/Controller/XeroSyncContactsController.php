<?php

namespace Drupal\associatedps_affidavit_template\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Handles Xero contact sync results page and Batch API callbacks.
 *
 * Batch callbacks are static so Drupal can serialize the operation queue
 * without dragging service container references through.
 */
class XeroSyncContactsController extends ControllerBase {

  const TEMPSTORE_COLLECTION = 'associatedps_affidavit_template.xero_sync_contacts';
  const TEMPSTORE_KEY = 'last_results';

  protected PrivateTempStoreFactory $tempStoreFactory;

  public function __construct(PrivateTempStoreFactory $temp_store_factory) {
    $this->tempStoreFactory = $temp_store_factory;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('tempstore.private'));
  }

  /**
   * Results page — renders the table written by syncBatchFinished().
   */
  public function results(): array {
    $store = $this->tempStoreFactory->get(self::TEMPSTORE_COLLECTION);
    $results = $store->get(self::TEMPSTORE_KEY);

    if (empty($results)) {
      return [
        '#markup' => $this->t('No sync has been run yet, or the previous results have expired. <a href=":url">Start a sync</a>.', [
          ':url' => Url::fromRoute('associatedps_affidavit_template.xero_sync_contacts')->toString(),
        ]),
      ];
    }

    $rows = [];
    foreach ($results['rows'] as $row) {
      $rows[] = [
        ['data' => $row['nid']],
        ['data' => $row['name']],
        [
          'data' => $row['status'],
          'class' => ['xero-sync-status', 'xero-sync-status--' . $row['status_key']],
        ],
        ['data' => $row['xero_contact_id'] ?? ''],
        ['data' => $row['message'] ?? ''],
      ];
    }

    $build = [];
    $build['summary'] = [
      '#type' => 'item',
      '#markup' => $this->t('Sync finished at @when. Success: <strong>@ok</strong>, Failed: <strong>@fail</strong>, Total: <strong>@total</strong>.', [
        '@when' => \Drupal::service('date.formatter')->format($results['finished_at'], 'medium'),
        '@ok' => $results['ok'],
        '@fail' => $results['fail'],
        '@total' => $results['ok'] + $results['fail'],
      ]),
    ];
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Client NID'),
        $this->t('Name'),
        $this->t('Status'),
        $this->t('Xero ContactID'),
        $this->t('Message'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No contacts were processed.'),
    ];
    $build['actions'] = [
      '#type' => 'container',
      'run_again' => [
        '#type' => 'link',
        '#title' => $this->t('Run Sync Again'),
        '#url' => Url::fromRoute('associatedps_affidavit_template.xero_sync_contacts'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
    ];

    return $build;
  }

  /**
   * Batch operation callback. Processes one chunk of Client node IDs.
   *
   * @param int[] $client_nids
   * @param array $context
   */
  public static function syncBatchProcess(array $client_nids, array &$context): void {
    if (!isset($context['results']['rows'])) {
      $context['results']['rows'] = [];
      $context['results']['ok'] = 0;
      $context['results']['fail'] = 0;
    }

    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $node_storage->loadMultiple($client_nids);

    /** @var \Drupal\associatedps_affidavit_template\Service\XeroContactMapper $mapper */
    $mapper = \Drupal::service('associatedps_affidavit_template.xero_contact_mapper');
    /** @var \Drupal\associatedps_affidavit_template\Service\XeroContactStore $store */
    $store = \Drupal::service('associatedps_affidavit_template.xero_contact_store');
    $contacts_payload = [];
    $by_index = [];
    foreach (array_values($nodes) as $i => $node) {
      $stored_id = $store->get((int) $node->id());
      $contacts_payload[] = $mapper->mapClientNode($node, $stored_id);
      $by_index[$i] = $node;
    }

    if (empty($contacts_payload)) {
      return;
    }

    /** @var \Drupal\associatedps_affidavit_template\Service\XeroClient $xero */
    $xero = \Drupal::service('associatedps_affidavit_template.xero_client');
    $response = $xero->createContacts($contacts_payload);

    if (isset($response['error'])) {
      // Whole-chunk failure (network, auth, etc.) — record every contact
      // in this chunk as failed with the same reason.
      foreach ($by_index as $node) {
        $context['results']['rows'][] = [
          'nid' => $node->id(),
          'name' => $node->label(),
          'status' => t('Failed'),
          'status_key' => 'fail',
          'xero_contact_id' => '',
          'message' => $response['error'],
        ];
        $context['results']['fail']++;
      }
      $context['message'] = t('Chunk failed: @msg', ['@msg' => mb_substr($response['error'], 0, 120)]);
      return;
    }

    $returned = $response['Contacts'] ?? [];
    foreach ($by_index as $i => $node) {
      $xero_row = $returned[$i] ?? NULL;
      if ($xero_row === NULL) {
        $context['results']['rows'][] = [
          'nid' => $node->id(),
          'name' => $node->label(),
          'status' => t('Unknown'),
          'status_key' => 'fail',
          'xero_contact_id' => '',
          'message' => t('Xero did not return a row for this contact.'),
        ];
        $context['results']['fail']++;
        continue;
      }

      $errors = $xero_row['ValidationErrors'] ?? [];
      $has_errors = !empty($errors);
      if ($has_errors) {
        $messages = array_map(static fn ($e) => $e['Message'] ?? '', $errors);
        $context['results']['rows'][] = [
          'nid' => $node->id(),
          'name' => $node->label(),
          'status' => t('Failed'),
          'status_key' => 'fail',
          'xero_contact_id' => $xero_row['ContactID'] ?? '',
          'message' => implode(' | ', array_filter($messages)),
        ];
        $context['results']['fail']++;
      }
      else {
        $contact_id = (string) ($xero_row['ContactID'] ?? '');
        if ($contact_id !== '') {
          $store->set((int) $node->id(), $contact_id);
        }
        $context['results']['rows'][] = [
          'nid' => $node->id(),
          'name' => $node->label(),
          'status' => t('Synced'),
          'status_key' => 'ok',
          'xero_contact_id' => $contact_id,
          'message' => '',
        ];
        $context['results']['ok']++;
      }
    }

    $context['message'] = t('Synced @n Client record(s).', ['@n' => count($by_index)]);
  }

  /**
   * Batch finished callback.
   */
  public static function syncBatchFinished(bool $success, array $results, array $operations): void {
    $store = \Drupal::service('tempstore.private')->get(self::TEMPSTORE_COLLECTION);
    $payload = [
      'finished_at' => \Drupal::time()->getRequestTime(),
      'rows' => $results['rows'] ?? [],
      'ok' => $results['ok'] ?? 0,
      'fail' => $results['fail'] ?? 0,
    ];
    $store->set(self::TEMPSTORE_KEY, $payload);

    $messenger = \Drupal::messenger();
    if ($success) {
      $messenger->addStatus(t('Xero contact sync finished. Success: @ok, Failed: @fail.', [
        '@ok' => $payload['ok'],
        '@fail' => $payload['fail'],
      ]));
    }
    else {
      $messenger->addError(t('Xero contact sync did not complete successfully — see results page for partial output.'));
    }
  }

}
