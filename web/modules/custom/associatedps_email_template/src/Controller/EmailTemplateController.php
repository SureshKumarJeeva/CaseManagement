<?php

namespace Drupal\associatedps_email_template\Controller;

use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * For created routes associatedps_email_template/{}.
 */
class EmailTemplateController {

  /**
   * {@inheritdoc}
   */
  public static function createEmailTemplate($node_id, $job_id = '', $content_type = '') {

    $node = Node::load($node_id);
    global $base_url;

    if ($node->getType() === 'email_preview') {

      $subject = $node->field_email_subject->value;
      $msg = $node->body->value;
      $email = $node->field_client_email->value;

      // Send via Microsoft 365 Graph (app-only). Legacy SMTP preserved in
      // self::sendViaSmtp('SEND_EMAIL_TO_CLIENT', $email, $subject, $msg).
      $result = self::sendViaGraph($email, $subject, $msg);

      if ($result['success']) {
        \Drupal::messenger()->addMessage(t('Your mail has been sent.'));
        return new RedirectResponse("/node/$node_id");
      }
      else {
        \Drupal::messenger()->addError(t('There was a problem sending your message and it was not sent.'));
        return new RedirectResponse("/node/$node_id");
      }
    }

    elseif ($node->getType() === 'client_update') {

      $job_node = Node::load($job_id);
      $title = $job_node->title->value;
      $subject = 'Client update on ' . $title;
      $job_url = $base_url . '/node/' . $job_id;
      $msg = $job_url . $node->field_client_update->value;
      $email = $job_node->field_client_s_job_email->value;

      // Admin notification recipient (env-driven; no address hardcoded).
      $admin_to = getenv('APS_ADMIN_EMAIL') ?: '';

      // Send via Microsoft 365 Graph (app-only). Sends as the fixed mailbox; the
      // client email is set as reply-to (Graph can't send AS an arbitrary from).
      // Legacy SMTP preserved in
      // self::sendViaSmtp('SEND_EMAIL_TO_ADMIN', $admin_to, $subject, $msg, $email).
      $result = self::sendViaGraph($admin_to, $subject, $msg, ['reply_to' => $email]);

      if ($result['success']) {
        \Drupal::messenger()->addMessage(t('Your mail has been sent.'));
        return new RedirectResponse("/node/$job_id");
      }
      else {
        \Drupal::messenger()->addError(t('There was a problem sending your message and it was not sent.'));
        return new RedirectResponse("/node/$job_id");
      }
    }

    elseif ($node->getType() === 'online_instruction') {

      $userCurrent = \Drupal::currentUser();
      $user = User::load($userCurrent->id());
      $user_name = $user->name->value;
      $roles = $user->getRoles();
      $subject = $node->title->value;
      $url = $base_url . '/node/' . $node_id;
      $msg = ($job_id === 'true') ? 'Client ' . $user_name . ' added Online Instruction at ' . $url : $roles[1] . ' ' . $user_name . ' updated Online Instruction at ' . $url;
      $email = $node->getOwner()->getEmail();

      // Admin notification recipient (env-driven; no address hardcoded).
      $admin_to = getenv('APS_ADMIN_EMAIL') ?: '';

      // Send via Microsoft 365 Graph (app-only); owner email as reply-to. Legacy
      // SMTP preserved in
      // self::sendViaSmtp('SEND_EMAIL_TO_ADMIN', $admin_to, $subject, $msg, $email).
      $result = self::sendViaGraph($admin_to, $subject, $msg, ['reply_to' => $email]);

      if ($result['success']) {
        \Drupal::messenger()->addMessage(t('Your mail has been sent.'));
        return ($roles[1] === 'client') ? new RedirectResponse("/online-instruction") : new RedirectResponse("/online_instruction");
      }
      else {
        \Drupal::messenger()->addError(t('There was a problem sending your message and it was not sent.'));
        return ($roles[1] === 'client') ? new RedirectResponse("/online-instruction") : new RedirectResponse("/online_instruction");
      }
    }

    else {

      // 'job' (case-status / instructions hooks) and 'cron' (recurring
      // attendance updates) both pass the template node id directly. Only the
      // legacy path resolves the template from the client's field_email_template.
      if ($content_type === 'job' || $content_type === 'cron') {
        $template_id = $node_id;
      }
      else {
        $template_id = $node->field_email_template->getValue();
        $template_id = $template_id[0]['target_id'];
      }

      $moduleHandler = \Drupal::moduleHandler();
      $moduleExist = $moduleHandler->moduleExists('associatedps_pdf_templates');
      if ($moduleExist) {
        $template_array = _associatedps_pdf_templates_library_data($template_id);
        \Drupal::logger('associatedps_email_template')->notice('Template Array: <pre>@template_array</pre>', ['@template_array' => print_r($template_array, TRUE)]);
        foreach (\Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'job') as $field_name => $field_definition) {
          if (!empty($field_definition->getTargetBundle())) {
            $elements[$field_name]['type'] = $field_definition->getType();
            $elements[$field_name]['label'] = $field_definition->getLabel();
            $elements[$field_name]['target_type'] = $field_definition->getSetting('target_type');
          }
        }
        \Drupal::logger('associatedps_email_template')->notice('Elements: <pre>@elements</pre>', ['@elements' => print_r($elements, TRUE)]);
        // Load the data of job content type.
        $job_list = Node::load($job_id);
        if (!empty($job_list)) {

          // Email send on change case status.
          $case_status = $job_list->field_case_status->value;

          // Call the function for replace the token.
          $email_template = _associatedps_pdf_templates_replace_value($elements, $job_list, $template_array);
          \Drupal::logger('associatedps_email_template')->notice('Email Template after token replacement: <pre>@email_template</pre>', ['@email_template' => print_r($email_template, TRUE)]);
          $msg = $email_template['body'];

          // Replace the custom [attendance] token with the job's Attendance Note
          // details (used by the "Attendance Update" recurring cron email).
          if (strpos((string) $msg, '[attendance]') !== FALSE) {
            $msg = str_replace('[attendance]', self::renderAttendanceNotes($job_list), $msg);
          }
          $email = $job_list->field_client_s_job_email->value;
          $subject = $email_template['subject'];

          // Send via Microsoft 365 Graph (app-only). Legacy SMTP preserved in
          // self::sendViaSmtp('SEND_EMAIL_TO_CLIENT', $email, $subject, $msg).
          $result = self::sendViaGraph($email, $subject, $msg);

          if ($result['success']) {

            if ($content_type === 'job') {
              // Only stamp the marker when the status actually changed, so a
              // reused 'job' send (e.g. Instructions Received) does not
              // overwrite/re-save it unnecessarily.
              if ($case_status !== $job_list->field_case_status_value->value) {
                $job_list->set('field_case_status_value', $case_status);
                $job_list->save();
              }

              // --- mailto popup DISABLED — Graph send is silent/background, so
              //     we no longer open the user's OS mail client. PRESERVED for
              //     recovery: this stashed the data that custom.js (behavior
              //     associatedpsMailtoTrigger) + associatedps_forms.module read
              //     to launch the mailto. To restore, re-enable this block and
              //     the matching reader + JS behavior. ---
              // $plain_body = strip_tags($msg);
              // \Drupal::service('tempstore.private')
              //   ->get('associatedps_email_template')
              //   ->set('mailto_data', [
              //     'to' => $email,
              //     'subject' => $subject,
              //     'body' => $plain_body,
              //   ]);
            }
            else {
              if ($job_list->hasField('field_last_mail_time')) {
                $job_list->set('field_last_mail_time', date('Y-m-d\Th:i:s', time()));
                $job_list->save();
              }
            }
            \Drupal::messenger()->addMessage(t('Your mail has been sent.'));
          }
          else {
            \Drupal::messenger()->addError(t('There was a problem sending your message and it was not sent.'));
          }
        }

      }
    }
  }

  /**
   * Send an HTML email through Microsoft 365 Graph (app-only). ACTIVE path.
   *
   * The body is run through the full_html text format first, matching the
   * previous hook_mail() behaviour, then handed to Microsoft365MailService which
   * sends as the fixed sender mailbox with saveToSentItems=true.
   *
   * @param string $to
   *   Recipient address.
   * @param string $subject
   *   Subject.
   * @param string $html_body
   *   HTML body (token replacement already done by the caller).
   * @param array $options
   *   Optional 'cc', 'bcc' (arrays) and 'reply_to' (string) — see the service.
   *
   * @return array
   *   ['success' => bool, 'error' => string|null].
   */
  protected static function sendViaGraph($to, $subject, $html_body, array $options = []) {
    /** @var \Drupal\associatedps_email_template\Service\Microsoft365MailService $mail */
    $mail = \Drupal::service('associatedps_email_template.m365_mail');
    $body = (string) check_markup((string) ($html_body ?? ''), 'full_html');
    return $mail->sendMail((string) $to, (string) $subject, $body, $options);
  }

  /**
   * LEGACY: send via Drupal's mailManager (admin SMTP). PRESERVED for recovery.
   *
   * This is the original send path used before the Microsoft 365 Graph switch,
   * kept intact (including the plugin.manager.mail config) so it can be restored
   * if Graph sending ever needs to be rolled back: replace the
   * self::sendViaGraph(...) calls with self::sendViaSmtp(...). Not called now.
   * Returns the same shape as sendViaGraph().
   *
   * @param string $key
   *   hook_mail key: 'SEND_EMAIL_TO_CLIENT' or 'SEND_EMAIL_TO_ADMIN'.
   * @param string $to
   *   Recipient address.
   * @param string $subject
   *   Subject.
   * @param string $msg
   *   HTML body.
   * @param string|null $from
   *   From address (used by the SEND_EMAIL_TO_ADMIN key only).
   *
   * @return array
   *   ['success' => bool, 'error' => string|null].
   */
  protected static function sendViaSmtp($key, $to, $subject, $msg, $from = NULL) {
    $mailManager = \Drupal::service('plugin.manager.mail');
    $module = 'associatedps_email_template';
    $params = [
      'message' => $msg ?? '',
      'subject' => $subject ?? '',
    ];
    if ($from !== NULL) {
      $params['from'] = $from;
    }
    $langcode = \Drupal::currentUser()->getPreferredLangcode();
    $send = TRUE;
    $result = $mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);
    return ['success' => !empty($result['result']), 'error' => NULL];
  }

  /**
   * Render ALL Attendance Note paragraphs of a job as an HTML table.
   *
   * Used to replace the [attendance] token in the "Attendance Update" recurring
   * cron email. One row per attendance note (Date/Time, Employee, Address) for
   * every note currently on the job.
   *
   * @param \Drupal\node\Entity\Node $job_node
   *   The job node.
   *
   * @return string
   *   HTML markup, or '' when the job has no attendance notes.
   */
  protected static function renderAttendanceNotes($job_node) {
    if (!$job_node->hasField('field_attendance_notes') || $job_node->get('field_attendance_notes')->isEmpty()) {
      return '';
    }

    $date_formatter = \Drupal::service('date.formatter');
    $cell = 'padding:4px 8px;border:1px solid #ccc;';
    $rows = '';

    // referencedEntities() returns EVERY attendance_note paragraph on the job.
    foreach ($job_node->get('field_attendance_notes')->referencedEntities() as $note) {
      // Date and Time.
      $when = '';
      if ($note->hasField('field_date_time') && !$note->get('field_date_time')->isEmpty()) {
        $raw = $note->get('field_date_time')->value;
        $ts = strtotime($raw);
        $when = $ts ? $date_formatter->format($ts, 'custom', 'd/m/Y h:i A') : $raw;
      }

      // Employee — sourced from the user reference (field_by). The user's name
      // lives in field_address (given_name + family_name), the same source
      // _associatedps_forms_get_users() uses; username as last resort.
      $employee = '';
      if ($note->hasField('field_by') && !$note->get('field_by')->isEmpty()) {
        $emp_user = $note->get('field_by')->entity;
        if ($emp_user) {
          if ($emp_user->hasField('field_address') && !$emp_user->get('field_address')->isEmpty()) {
            $addr = $emp_user->get('field_address')->first()->getValue();
            $employee = trim(($addr['given_name'] ?? '') . ' ' . ($addr['family_name'] ?? ''));
          }
          if ($employee === '') {
            $employee = $emp_user->getDisplayName();
          }
        }
      }

      // Address (single address value belonging to THIS note).
      $address = '';
      if ($note->hasField('field_address_attendance') && !$note->get('field_address_attendance')->isEmpty()) {
        $a = $note->get('field_address_attendance')->first()->getValue();
        $parts = array_filter([
          $a['address_line1'] ?? '',
          $a['address_line2'] ?? '',
          $a['locality'] ?? '',
          $a['administrative_area'] ?? '',
          $a['postal_code'] ?? '',
        ]);
        $address = implode(', ', $parts);
      }

      $rows .= '<tr>'
        . '<td style="' . $cell . '">' . htmlspecialchars((string) $when) . '</td>'
        . '<td style="' . $cell . '">' . htmlspecialchars((string) $employee) . '</td>'
        . '<td style="' . $cell . '">' . htmlspecialchars((string) $address) . '</td>'
        . '</tr>';
    }

    if ($rows === '') {
      return '';
    }

    $head = 'padding:4px 8px;border:1px solid #ccc;text-align:left;';
    return '<table style="border-collapse:collapse;border:1px solid #ccc;">'
      . '<thead><tr>'
      . '<th style="' . $head . '">Date &amp; Time</th>'
      . '<th style="' . $head . '">Employee</th>'
      . '<th style="' . $head . '">Address</th>'
      . '</tr></thead><tbody>' . $rows . '</tbody></table>';
  }

}
