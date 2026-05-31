<?php

namespace Drupal\associatedps_affidavit_template\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form that kicks off a Batch sync of Client nodes to Xero.
 */
class XeroSyncContactsForm extends FormBase {

  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function getFormId(): string {
    return 'associatedps_affidavit_template_xero_sync_contacts_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $client_ids = $this->getPublishedClientIds();
    $count = count($client_ids);

    $form['intro'] = [
      '#type' => 'item',
      '#markup' => $this->t('This will push <strong>@count</strong> published <em>Client</em> record(s) from this site to your connected Xero account as contacts. Existing Xero contacts with the same Name will be updated; new ones will be created.', [
        '@count' => $count,
      ]),
    ];

    if ($count === 0) {
      $form['empty'] = [
        '#type' => 'item',
        '#markup' => $this->t('No published Client records were found. Nothing to sync.'),
      ];
      return $form;
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start Sync'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('associatedps_affidavit_template.xero_account'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $client_ids = $this->getPublishedClientIds();
    if (empty($client_ids)) {
      $this->messenger()->addWarning($this->t('No Client records to sync.'));
      return;
    }

    $chunks = array_chunk($client_ids, 25);
    $operations = [];
    foreach ($chunks as $chunk) {
      $operations[] = [
        ['\Drupal\associatedps_affidavit_template\Controller\XeroSyncContactsController', 'syncBatchProcess'],
        [$chunk],
      ];
    }

    $batch = [
      'title' => $this->t('Syncing Clients to Xero…'),
      'init_message' => $this->t('Preparing contacts…'),
      'progress_message' => $this->t('Processed @current of @total batches.'),
      'error_message' => $this->t('An error occurred while syncing contacts to Xero.'),
      'operations' => $operations,
      'finished' => ['\Drupal\associatedps_affidavit_template\Controller\XeroSyncContactsController', 'syncBatchFinished'],
    ];

    batch_set($batch);
    $form_state->setRedirectUrl(Url::fromRoute('associatedps_affidavit_template.xero_sync_contacts_results'));
  }

  /**
   * Loads published Client node IDs.
   *
   * @return int[]
   */
  protected function getPublishedClientIds(): array {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'client')
      ->condition('status', 1)
      ->sort('nid');
    $ids = $query->execute();
    return array_values($ids);
  }

}
