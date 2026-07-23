<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Secret;

use JsonException;
use PeanutAdmin\Settings\Application\SettingException;
use Throwable;

final readonly class SodiumSecretProtector implements SecretProtector
{
    /** @param array<string, string> $keys */
    public function __construct(
        private array $keys,
        private string $activeKeyId,
    ) {
        if ($keys === [] || !isset($keys[$activeKeyId])) {
            throw self::unavailable();
        }
        foreach ($keys as $keyId => $key) {
            if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $keyId) !== 1
                || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw self::unavailable();
            }
        }
    }

    public static function fromJson(string $json, string $activeKeyId): self
    {
        if ($json === '' || $activeKeyId === '' || self::hasDuplicateKeys($json)) {
            throw self::unavailable();
        }
        try {
            $encoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::unavailable();
        }
        if (!is_array($encoded) || array_is_list($encoded)) {
            throw self::unavailable();
        }
        $keys = [];
        foreach ($encoded as $keyId => $value) {
            if (!is_string($keyId) || !is_string($value)) {
                throw self::unavailable();
            }
            $decoded = base64_decode($value, true);
            if (!is_string($decoded)) {
                throw self::unavailable();
            }
            $keys[$keyId] = $decoded;
        }

        return new self($keys, $activeKeyId);
    }

    public function protect(string $plaintext, SecretStorageContext $context): array
    {
        if ($plaintext === '' || strlen($plaintext) > 4096) {
            throw SettingException::invalid('SETTING_VALUE_INVALID', 'A secret setting requires a non-empty bounded value.');
        }
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $context->additionalAuthenticatedData($this->activeKeyId),
                $nonce,
                $this->keys[$this->activeKeyId],
            );
        } catch (Throwable) {
            throw self::unavailable();
        }
        if ($ciphertext === '' || strlen($ciphertext) > 8192) {
            throw self::unavailable();
        }

        return ['ciphertext' => $ciphertext, 'nonce' => $nonce, 'key_id' => $this->activeKeyId];
    }

    public function reveal(
        string $ciphertext,
        string $nonce,
        string $keyId,
        SecretStorageContext $context,
    ): string {
        $key = $this->keys[$keyId] ?? null;
        if (!is_string($key)
            || $ciphertext === ''
            || strlen($ciphertext) > 8192
            || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw self::unavailable();
        }
        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                $context->additionalAuthenticatedData($keyId),
                $nonce,
                $key,
            );
        } catch (Throwable) {
            throw self::unavailable();
        }
        if (!is_string($plaintext) || $plaintext === '' || strlen($plaintext) > 4096) {
            throw self::unavailable();
        }

        return $plaintext;
    }

    private static function unavailable(): SettingException
    {
        return SettingException::unavailable(
            'SETTING_SECRET_UNAVAILABLE',
            'The setting secret protector is unavailable.',
        );
    }

    private static function hasDuplicateKeys(string $json): bool
    {
        $count = preg_match_all('/"((?:\\\\.|[^"\\\\])*)"\s*:/', $json, $matches);
        if (!is_int($count) || $count < 1) {
            return false;
        }
        $seen = [];
        foreach ($matches[1] as $encodedKey) {
            try {
                $key = json_decode('"' . $encodedKey . '"', true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return true;
            }
            if (!is_string($key) || isset($seen[$key])) {
                return true;
            }
            $seen[$key] = true;
        }

        return false;
    }
}
