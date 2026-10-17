<?php

namespace Drupal\associatedps_affidavit_template\Service;

/**
 * DocuSign client bound to the separate Document Generation sandbox account.
 *
 * Identical wire protocol to DocuSignClient — same JWT grant, same REST
 * endpoints — but pointed at a DIFFERENT DocuSign account. Document
 * generation is trialled under its own developer/sandbox account so that
 * experimenting with docgen templates cannot touch, throttle, or consume
 * envelope quota on the account the live tab-based affidavit flow runs on.
 *
 * Everything that separates the two accounts is expressed by three overrides:
 *
 *   - envPrefix()   → reads DOCUSIGN_DOCGEN_* env vars instead of DOCUSIGN_*
 *   - tokenRowId()  → row 2 of docusign_tokens, so the two access tokens
 *                     never overwrite each other (a token minted for account A
 *                     is rejected by account B)
 *   - loggerChannel() → its own watchdog channel, so docgen troubleshooting
 *                     doesn't have to be untangled from live affidavit traffic
 *
 * Non-identifying settings fall back to the main credential set (see env()),
 * because they are properties of DocuSign itself rather than of the account:
 * the demo OAuth host and the JWT scope list are the same either way. The
 * identifying ones — integration key, impersonated user, RSA private key,
 * owner email, redirect URI — deliberately do NOT fall back: silently
 * borrowing the live account's identity is exactly the failure this class
 * exists to prevent, so an unset value surfaces as "not configured".
 */
class DocuSignDocGenClient extends DocuSignClient {

  /**
   * Settings safe to inherit from the main DocuSign credential set.
   *
   * @var string[]
   */
  protected const INHERITABLE = [
    'OAUTH_BASE',
    'JWT_SCOPES',
    'OWNER_NAME',
  ];

  /**
   * {@inheritdoc}
   */
  protected function envPrefix(): string {
    return 'DOCUSIGN_DOCGEN_';
  }

  /**
   * {@inheritdoc}
   */
  protected function tokenRowId(): int {
    return 2;
  }

  /**
   * {@inheritdoc}
   */
  protected function loggerChannel(): string {
    return 'docusign_docgen';
  }

  /**
   * {@inheritdoc}
   *
   * Falls back to the DOCUSIGN_* value for non-identifying settings only.
   */
  protected function env(string $key, string $default = ''): string {
    $value = getenv($this->envPrefix() . $key);
    if ($value !== FALSE && $value !== '') {
      return $value;
    }
    if (in_array($key, static::INHERITABLE, TRUE)) {
      $inherited = getenv('DOCUSIGN_' . $key);
      if ($inherited !== FALSE && $inherited !== '') {
        return $inherited;
      }
    }
    return $default;
  }

  /**
   * Whether the docgen sandbox account has its own credentials configured.
   *
   * Checked before any docgen UI is offered, so a half-configured install
   * fails with a clear message instead of an opaque JWT error.
   */
  public function isConfigured(): bool {
    return $this->integrationKey !== ''
      && $this->userId !== ''
      && $this->loadPrivateKey() !== '';
  }

  /**
   * Human-readable reason isConfigured() is FALSE (empty string when it is TRUE).
   */
  public function getConfigurationError(): string {
    $missing = [];
    if ($this->integrationKey === '') {
      $missing[] = 'DOCUSIGN_DOCGEN_INTEGRATION_KEY';
    }
    if ($this->userId === '') {
      $missing[] = 'DOCUSIGN_DOCGEN_USER_ID';
    }
    if ($this->loadPrivateKey() === '') {
      $missing[] = 'DOCUSIGN_DOCGEN_PRIVATE_KEY_PATH (or DOCUSIGN_DOCGEN_PRIVATE_KEY)';
    }
    if (!$missing) {
      return '';
    }
    return 'The DocuSign Document Generation sandbox account is not configured. Missing in .env: '
      . implode(', ', $missing) . '.';
  }

}
