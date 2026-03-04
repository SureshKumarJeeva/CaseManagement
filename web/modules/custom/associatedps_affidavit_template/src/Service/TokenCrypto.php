<?php

namespace Drupal\associatedps_affidavit_template\Service;

use Drupal\Core\Site\Settings;

class TokenCrypto {

  private string $key;
  private string $cipher = 'AES-256-CBC';

  public function __construct(Settings $settings) {
    $this->key = hash('sha256', $settings->get('xero_encryption_key'), true);
  }

  public function encrypt(string $plainText): string {
    $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
    $encrypted = openssl_encrypt($plainText, $this->cipher, $this->key, 0, $iv);

    return base64_encode($iv . $encrypted);
  }

  public function decrypt(string $encryptedText): string {
    $data = base64_decode($encryptedText);
    $ivLength = openssl_cipher_iv_length($this->cipher);

    $iv = substr($data, 0, $ivLength);
    $cipherText = substr($data, $ivLength);

    return openssl_decrypt($cipherText, $this->cipher, $this->key, 0, $iv);
  }
}