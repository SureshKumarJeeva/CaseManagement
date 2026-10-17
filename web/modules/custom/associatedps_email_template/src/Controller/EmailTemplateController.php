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
      $email = $node->getOwner()->getEmail();

      // Admin notification recipient (env-driven; no address hardcoded).
      $admin_to = getenv('APS_ADMIN_EMAIL') ?: '';

      // Fallback subject + body — used when no template is configured or the
      // rendering engine is unavailable, so the admin notification never
      // silently stops.
      $subject = $node->title->value;
      $url = $base_url . '/node/' . $node_id;
      $msg = ($job_id === 'true') ? 'Client ' . $user_name . ' added Online Instruction at ' . $url : $roles[1] . ' ' . $user_name . ' updated Online Instruction at ' . $url;

      // Preferred: render the admin email from the Library "Email" template
      // selected in the Online Instruction Email settings form
      // (associatedps_case_status_template.settings:email_template), filling
      // [node:field_*] tokens with THIS instruction's values. The token list
      // comes from the template's field_instruction_tokens (kept separate from
      // the Job field_available_tokens so the two token sets never mix).
      $template_id = \Drupal::config('associatedps_case_status_template.settings')->get('email_template');
      $moduleHandler = \Drupal::moduleHandler();
      // Attachments built from the uploaded documents, only when the template
      // opts in via the [node:field_upload_documents] token (see below).
      $attachments = [];
      if (!empty($template_id) && $template_id !== '_none'
          && $moduleHandler->moduleExists('associatedps_pdf_templates')
          && Node::load($template_id)) {
        $template_array = _associatedps_pdf_templates_library_data($template_id, 'field_instruction_tokens');
        if (!empty($template_array)) {
          // Field-definition map for the online_instruction bundle (drives token
          // type/formatting in the replacer).
          $elements = [];
          foreach (\Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'online_instruction') as $field_name => $field_definition) {
            if (!empty($field_definition->getTargetBundle())) {
              $elements[$field_name]['type'] = $field_definition->getType();
              $elements[$field_name]['label'] = $field_definition->getLabel();
              $elements[$field_name]['target_type'] = $field_definition->getSetting('target_type');
            }
          }

          // Documents token: when present in the template, the uploaded files are
          // ATTACHED to the email (not rendered inline). Pull this token out of the
          // generic replace pass so it isn't blanked, then substitute it with the
          // attached filenames after rendering.
          $docs_token = '[node:field_upload_documents]';
          $has_docs_token = !empty($template_array['tokens'])
            && in_array($docs_token, array_map('trim', $template_array['tokens']), TRUE);
          if ($has_docs_token) {
            $template_array['tokens'] = array_values(array_filter(
              $template_array['tokens'],
              fn ($t) => trim($t) !== $docs_token
            ));
          }

          $rendered = _associatedps_pdf_templates_replace_value($elements, $node, $template_array);
          if (!empty($rendered['body'])) {
            $msg = $rendered['body'];
            if (!empty($rendered['subject'])) {
              $subject = $rendered['subject'];
            }
          }

          if ($has_docs_token) {
            [$attachments, $names, $skipped] = self::collectInstructionAttachments($node);
            // Replace the documents token in the rendered body with the filenames
            // (attached) plus any skipped (too large) listed by name.
            $list = array_merge($names, array_map(fn ($s) => $s . ' (too large to attach)', $skipped));
            $msg = str_replace($docs_token, $list ? implode(', ', $list) : '', $msg);
          }
        }
      }
      else {
        \Drupal::logger('associatedps_email_template')->warning('Online Instruction @nid: no email template configured (associatedps_case_status_template.settings); sent fallback notification.', ['@nid' => $node_id]);
      }

      // Send via Microsoft 365 Graph (app-only); owner email as reply-to. Legacy
      // SMTP preserved in
      // self::sendViaSmtp('SEND_EMAIL_TO_ADMIN', $admin_to, $subject, $msg, $email).
      $result = self::sendViaGraph($admin_to, $subject, $msg, ['reply_to' => $email, 'attachments' => $attachments]);

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

      // 'job' (case-status / instructions hooks), 'cron' (recurring attendance
      // updates) and 'manual' (the Job view page "Update Email" button) all pass
      // the template node id directly. Only the legacy path resolves the
      // template from the client's field_email_template.
      if (in_array($content_type, ['job', 'cron', 'manual'], TRUE)) {
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
            // 'manual' sends are independent of the cron schedule, so they do
            // NOT stamp field_last_mail_time (the cron's countdown anchor).
            elseif ($content_type !== 'manual') {
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
   *   Optional 'cc', 'bcc' (arrays), 'reply_to' (string), and 'attachments'
   *   (array of ['name','contentType','content']) — see the service.
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
   * Collect file attachments from an online_instruction node's uploaded
   * documents, capped to stay within Graph's sendMail inline-size limit.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The online_instruction node.
   *
   * @return array
   *   [attachments, attachedNames, skippedNames]. attachments is an array of
   *   ['name','contentType','content'] for Microsoft365MailService::sendMail.
   */
  protected static function collectInstructionAttachments($node): array {
    $attachments = [];
    $names = [];
    $skipped = [];
    if (!$node->hasField('field_upload_documents') || $node->get('field_upload_documents')->isEmpty()) {
      return [$attachments, $names, $skipped];
    }
    // Graph sendMail carries attachments inline, so the whole request must stay
    // under ~4 MB (base64 adds ~33%). Cap per-file and cumulative raw bytes.
    $max_per_file = 3 * 1024 * 1024;
    $max_total = 3 * 1024 * 1024;
    $running = 0;
    $file_system = \Drupal::service('file_system');
    foreach ($node->get('field_upload_documents')->referencedEntities() as $file) {
      $name = $file->getFilename();
      $size = (int) $file->getSize();
      if ($size <= 0 || $size > $max_per_file || ($running + $size) > $max_total) {
        $skipped[] = $name;
        \Drupal::logger('associatedps_email_template')->warning('Online Instruction attachment skipped (size): @name (@size bytes).', ['@name' => $name, '@size' => $size]);
        continue;
      }
      $path = $file_system->realpath($file->getFileUri());
      if (!$path || !is_readable($path)) {
        $skipped[] = $name;
        continue;
      }
      $bytes = @file_get_contents($path);
      if ($bytes === FALSE) {
        $skipped[] = $name;
        continue;
      }
      $attachments[] = [
        'name' => $name,
        'contentType' => $file->getMimeType() ?: 'application/octet-stream',
        'content' => $bytes,
      ];
      $names[] = $name;
      $running += $size;
    }
    return [$attachments, $names, $skipped];
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
