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

      $mailManager = \Drupal::service('plugin.manager.mail');
      $module = 'associatedps_email_template';
      $key = 'SEND_EMAIL_TO_CLIENT';
      $to = $email;
      $params['message'] = $msg ?? '';
      $params['subject'] = $subject ?? '';
      $langcode = \Drupal::currentUser()->getPreferredLangcode();
      $send = TRUE;
      $result = $mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);

      if ($result['result'] == TRUE) {
        drupal_set_message(t('Your mail has been sent.'));
        $response = new RedirectResponse("/node/$node_id");
        $response->send();
      }
      else {
        drupal_set_message(t('There was a problem sending your message and it was not sent.'), 'error');
        $response = new RedirectResponse("/node/$node_id");
        $response->send();
      }
    }

    elseif ($node->getType() === 'client_update') {

      $job_node = Node::load($job_id);
      $title = $job_node->title->value;
      $subject = 'Client update on ' . $title;
      $job_url = $base_url . '/node/' . $job_id;
      $msg = $job_url . $node->field_client_update->value;
      $email = $job_node->field_client_s_job_email->value;

      $mailManager = \Drupal::service('plugin.manager.mail');
      $module = 'associatedps_email_template';
      $key = 'SEND_EMAIL_TO_ADMIN';
      $to = 'info@associatedps.com.au';
      $params['message'] = $msg ?? '';
      $params['subject'] = $subject ?? '';
      $params['from'] = $email;
      $langcode = \Drupal::currentUser()->getPreferredLangcode();
      $send = TRUE;
      $result = $mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);

      if ($result['result'] == TRUE) {
        drupal_set_message(t('Your mail has been sent.'));
        $response = new RedirectResponse("/node/$job_id");
        $response->send();
      }
      else {
        drupal_set_message(t('There was a problem sending your message and it was not sent.'), 'error');
        $response = new RedirectResponse("/node/$job_id");
        $response->send();
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

      $mailManager = \Drupal::service('plugin.manager.mail');
      $module = 'associatedps_email_template';
      $key = 'SEND_EMAIL_TO_ADMIN';
      $to = 'info@associatedps.com.au';
      $params['message'] = $msg ?? '';
      $params['subject'] = $subject ?? '';
      $params['from'] = $email;
      $langcode = \Drupal::currentUser()->getPreferredLangcode();
      $send = TRUE;
      $result = $mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);

      if ($result['result'] == TRUE) {
        drupal_set_message(t('Your mail has been sent.'));
        $response = ($roles[1] === 'client') ? new RedirectResponse("/online-instruction") : new RedirectResponse("/online_instruction");
        $response->send();
      }
      else {
        drupal_set_message(t('There was a problem sending your message and it was not sent.'), 'error');
        $response = ($roles[1] === 'client') ? new RedirectResponse("/online-instruction") : new RedirectResponse("/online_instruction");
        $response->send();
      }
    }

    else {

      if ($content_type === 'job') {
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

        foreach (\Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'job') as $field_name => $field_definition) {
          if (!empty($field_definition->getTargetBundle())) {
            $elements[$field_name]['type'] = $field_definition->getType();
            $elements[$field_name]['label'] = $field_definition->getLabel();
            $elements[$field_name]['target_type'] = $field_definition->getSetting('target_type');
          }
        }

        // Load the data of job content type.
        $job_list = Node::load($job_id);
        if (!empty($job_list)) {

          // Email send on change case status.
          $case_status = $job_list->field_case_status->value;

          // Call the function for replace the token.
          $email_template = _associatedps_pdf_templates_replace_value($elements, $job_list, $template_array);
          $msg = $email_template['body'];
          $email = $job_list->field_client_s_job_email->value;
          $subject = $email_template['subject'];

          $mailManager = \Drupal::service('plugin.manager.mail');
          $module = 'associatedps_email_template';
          $key = 'SEND_EMAIL_TO_CLIENT';
          $to = $email;
          $params['message'] = $msg ?? '';
          $params['subject'] = $subject ?? '';
          $langcode = \Drupal::currentUser()->getPreferredLangcode();
          $send = TRUE;
          $result = $mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);

          if ($result['result'] == TRUE) {

            if ($content_type === 'job') {
              $job_list->set('field_case_status_value', $case_status);
              $job_list->save();
            }
            else {
              if ($job_list->hasField('field_last_mail_time')) {
                $job_list->set('field_last_mail_time', date('Y-m-d\Th:i:s', time()));
                $job_list->save();
              }
            }
            drupal_set_message(t('Your mail has been sent.'));
          }
          else {
            drupal_set_message(t('There was a problem sending your message and it was not sent.'), 'error');
          }
        }

      }
    }
  }

}
