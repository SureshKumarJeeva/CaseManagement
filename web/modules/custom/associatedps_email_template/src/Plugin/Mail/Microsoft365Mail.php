<?php

namespace Drupal\associatedps_email_template\Plugin\Mail;

use Drupal\associatedps_email_template\Service\Microsoft365MailService;
use Drupal\Component\Utility\Html;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends Drupal mail through Microsoft 365 (Graph, app-only).
 *
 * Lets core-generated emails — e.g. the User module's "Notify user of new
 * account", password reset and account activation emails — go out through the
 * same Microsoft365MailService (from the fixed company mailbox) as the app's
 * own emails, instead of PHP mail(), which is not available on this server.
 *
 * Selected per module in the Mail System UI (Configuration > System > Mail
 * System > Module-specific configuration), e.g. Module "User", as both
 * Formatter and Sender.
 *
 * @Mail(
 *   id = "associatedps_m365_mail",
 *   label = @Translation("Microsoft 365 (Graph)"),
 *   description = @Translation("Sends email through Microsoft 365 Graph from the company mailbox.")
 * )
 */
class Microsoft365Mail implements MailInterface, ContainerFactoryPluginInterface {

  /**
   * The Microsoft 365 mail service.
   */
  protected Microsoft365MailService $m365;

  public function __construct(Microsoft365MailService $m365) {
    $this->m365 = $m365;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($container->get('associatedps_email_template.m365_mail'));
  }

  /**
   * {@inheritdoc}
   *
   * Joins the body parts into one HTML string. Core's user emails are plain
   * text, so text without HTML is escaped, its URLs made clickable and its line
   * breaks kept; a body that already contains HTML is passed through.
   */
  public function format(array $message) {
    $parts = [];
    foreach ((array) $message['body'] as $part) {
      $parts[] = (string) $part;
    }
    $body = implode("\n\n", $parts);

    // Real HTML tags only — text like "<APS>" or "Name <a@b.c>" is still text.
    if (!preg_match('~</?(p|br|a|div|span|table|tr|td|ul|ol|li|strong|b|em|i|h[1-6])(\s[^>]*)?/?>~i', $body)) {
      $body = Html::escape($body);
      $body = preg_replace('~(https?://[^\s<]+)~i', '<a href="$1">$1</a>', $body);
      $body = nl2br($body);
    }
    $message['body'] = Markup::create($body);
    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message) {
    $recipients = $this->parseAddresses((string) $message['to']);
    if (!$recipients) {
      return FALSE;
    }
    $options = [];
    if (count($recipients) > 1) {
      $options['cc'] = array_slice($recipients, 1);
    }
    $headers = array_change_key_case($message['headers'] ?? [], CASE_LOWER);
    if (!empty($headers['reply-to'])) {
      $reply_to = $this->parseAddresses((string) $headers['reply-to']);
      if ($reply_to) {
        $options['reply_to'] = $reply_to[0];
      }
    }

    $result = $this->m365->sendMail($recipients[0], (string) $message['subject'], (string) $message['body'], $options);
    return !empty($result['success']);
  }

  /**
   * Plain addresses from a header value such as "Name <a@b.c>, d@e.f".
   *
   * @return string[]
   *   Email addresses, in order.
   */
  protected function parseAddresses(string $value): array {
    $addresses = [];
    foreach (explode(',', $value) as $item) {
      $item = trim($item);
      if (preg_match('/<([^>]+)>/', $item, $m)) {
        $item = trim($m[1]);
      }
      if ($item !== '') {
        $addresses[] = $item;
      }
    }
    return $addresses;
  }

}
