<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Crypto;

use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;

final readonly class AesGcmWebhookSecretProtector implements WebhookSecretProtector
{
    private const AAD_PREFIX = 'peanut.integration-security.webhook-secret.v1|';

    private string $key;

    public function __construct(private string $keyId, string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if (preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $keyId) !== 1 || !is_string($key) || strlen($key) !== 32) {
            throw IntegrationSecurityException::secretInvalid();
        }
        $this->key = $key;
    }

    public function seal(string $secret, string $binding): array
    {
        if (strlen($secret) < 32 || strlen($secret) > 128) {
            throw IntegrationSecurityException::secretInvalid();
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, $this->aad($binding), 16);
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw IntegrationSecurityException::secretInvalid();
        }
        return [
            'ciphertext' => $this->encode($nonce) . '.' . $this->encode($tag) . '.' . $this->encode($ciphertext),
            'key_id' => $this->keyId,
        ];
    }

    public function open(string $ciphertext, string $keyId, string $binding): string
    {
        if (!hash_equals($this->keyId, $keyId)) {
            throw IntegrationSecurityException::secretInvalid();
        }
        $parts = explode('.', $ciphertext);
        if (count($parts) !== 3) {
            throw IntegrationSecurityException::secretInvalid();
        }
        [$nonce, $tag, $sealed] = array_map($this->decode(...), $parts);
        if (strlen($nonce) !== 12 || strlen($tag) !== 16 || $sealed === '') {
            throw IntegrationSecurityException::secretInvalid();
        }
        $plain = openssl_decrypt($sealed, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, $this->aad($binding));
        if (!is_string($plain) || strlen($plain) < 32 || strlen($plain) > 128) {
            throw IntegrationSecurityException::secretInvalid();
        }
        return $plain;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function aad(string $binding): string
    {
        if (preg_match('/^[1-9][0-9]*:webhook_[0-9a-f]{32}$/D', $binding) !== 1) {
            throw IntegrationSecurityException::secretInvalid();
        }
        return self::AAD_PREFIX . $binding;
    }

    private function decode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw IntegrationSecurityException::secretInvalid();
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if (!is_string($decoded)) {
            throw IntegrationSecurityException::secretInvalid();
        }
        return $decoded;
    }
}
