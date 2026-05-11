<?php

namespace Drupal\associatedps_affidavit_template\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class PandaDocWebhookController extends ControllerBase {

  /**
   * Handle incoming PandaDoc webhook events.
   *
   * Route: /pandadoc/webhook (POST, open access)
   * Always returns HTTP 200 to acknowledge receipt.
   */
  public function handle(Request $request) {
    $payload = json_decode($request->getContent(), TRUE);

    if (empty($payload)) {
      \Drupal::logger('pandadoc_webhook')->warning('Empty webhook payload received.');
      return new JsonResponse(['status' => 'ok']);
    }

    $event = $payload['event'] ?? $payload[0]['event'] ?? '';
    $data = $payload['data'] ?? $payload[0]['data'] ?? [];

    \Drupal::logger('pandadoc_webhook')->notice('Webhook received — event: @event', ['@event' => $event]);

    switch ($event) {
      case 'document_state_changed':
        $this->handleStateChanged($data);
        break;

      case 'document_completed':
        $this->handleCompleted($data);
        break;

      case 'document_creation_failed':
        $this->handleCreationFailed($data);
        break;

      default:
        \Drupal::logger('pandadoc_webhook')->notice('Unhandled webhook event: @event', ['@event' => $event]);
        break;
    }

    return new JsonResponse(['status' => 'ok']);
  }

  /**
   * Handle document_state_changed event.
   * Updates the pandadoc_documents tracking table with new status.
   */
  protected function handleStateChanged(array $data): void {
    $documentId = $data['id'] ?? '';
    $status = $data['status'] ?? '';

    if (empty($documentId) || empty($status)) {
      return;
    }

    \Drupal::logger('pandadoc_webhook')->notice('State changed — doc: @id, status: @status', [
      '@id' => $documentId,
      '@status' => $status,
    ]);

    \Drupal::database()->update('pandadoc_documents')
      ->fields([
        'status' => $status,
        'updated' => time(),
      ])
      ->condition('pandadoc_document_id', $documentId)
      ->execute();
  }

  /**
   * Handle document_completed event.
   * Logs completion — PDF is now ready for download.
   */
  protected function handleCompleted(array $data): void {
    $documentId = $data['id'] ?? '';

    \Drupal::logger('pandadoc_webhook')->notice('Document completed (PDF ready) — doc: @id', [
      '@id' => $documentId,
    ]);

    if (!empty($documentId)) {
      \Drupal::database()->update('pandadoc_documents')
        ->fields([
          'status' => 'document.completed',
          'updated' => time(),
        ])
        ->condition('pandadoc_document_id', $documentId)
        ->execute();
    }
  }

  /**
   * Handle document_creation_failed event.
   * Logs the error for debugging.
   */
  protected function handleCreationFailed(array $data): void {
    $documentId = $data['id'] ?? '';
    $error = $data['error'] ?? 'Unknown error';

    \Drupal::logger('pandadoc_webhook')->error('Document creation failed — doc: @id, error: @error', [
      '@id' => $documentId,
      '@error' => is_array($error) ? json_encode($error) : $error,
    ]);

    if (!empty($documentId)) {
      \Drupal::database()->update('pandadoc_documents')
        ->fields([
          'status' => 'creation_failed',
          'updated' => time(),
        ])
        ->condition('pandadoc_document_id', $documentId)
        ->execute();
    }
  }

}
