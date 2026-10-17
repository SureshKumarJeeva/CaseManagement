<?php

namespace Drupal\associatedps_update_client\Controller;

use Drupal\associatedps_email_template\Controller\EmailTemplateController;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * "Update Email" button on the Job view page.
 *
 * Sends the job's client the same "Attendance Update" email the recurring cron
 * sends (associatedps_email_template_cron()), straight from the view page.
 * It is a manual action, so it ignores the client's "Attendance Update -
 * Frequency" opt-in and does not touch the cron countdown (field_last_mail_time).
 */
class UpdateEmailController extends ControllerBase {

  /**
   * Sends the Attendance Update email and returns to the job.
   */
  public function send(NodeInterface $node) {
    $redirect = new RedirectResponse($node->toUrl()->toString());

    if ($node->bundle() !== 'job') {
      $this->messenger()->addError($this->t('Update Email is only available on jobs.'));
      return $redirect;
    }

    if (!$node->hasField('field_client_s_job_email') || $node->get('field_client_s_job_email')->isEmpty()) {
      $this->messenger()->addError($this->t("No email was sent: this job has no Client's Job Email."));
      return $redirect;
    }

    // Same template the cron uses: the Library "Email" item titled
    // "Attendance Update".
    $templates = $this->entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => 'library',
      'field_category' => 'Email',
      'title' => 'Attendance Update',
    ]);
    if (empty($templates)) {
      $this->messenger()->addError($this->t('No email was sent: there is no Email template titled "Attendance Update" in the Library.'));
      return $redirect;
    }

    // Sets its own "sent" / "problem sending" message.
    EmailTemplateController::createEmailTemplate(array_key_first($templates), $node->id(), 'manual');

    return $redirect;
  }

}
