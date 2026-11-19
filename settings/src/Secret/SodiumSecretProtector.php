<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Secret;

use JsonException;
use PeanutAdmin\Kernel\Protection\XChaCha20Keyring;
use RuntimeException;

final readonly class SodiumSecretProtector implements SecretProtector
{
    private XChaCha20Keyring $keyring;

    /** @param array<string, string> $keys */
    public function __construct(#[\SensitiveParameter] array $keys, string $activeKeyId)
    {
        try {
            $this->keyring = new XChaCha20Keyring($keys, $activeKeyId);
        } catch (RuntimeException) {
            throw SecretProtectionException::unavailable();
        }
    }

    public static function fromJson(#[\SensitiveParameter] string $json, string $activeKeyId): self
    {
        if ($json === '' || $activeKeyId === '' || self::hasDuplicateKeys($json)) {
            throw SecretProtectionException::unavailable();
        }
        try {
            $encoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw SecretProtectionException::unavailable();
        }
        if (!is_array($encoded) || array_is_list($encoded)) {
            throw SecretProtectionException::unavailable();
        }
        $keys = [];
        foreach ($encoded as $keyId => $value) {
            if (!is_string($keyId) || !is_string($value)) {
                throw SecretProtectionException::unavailable();
            }
            $decoded = base64_decode($value, true);
            if (!is_string($decoded)) {
                throw SecretProtectionException::unavailable();
            }
            $keys[$keyId] = $decoded;
        }

        return new self($keys, $activeKeyId);
    }

    public function protect(#[\SensitiveParameter] string $plaintext, SecretStorageContext $context): array
    {
        if ($plaintext === '' || strlen($plaintext) > XChaCha20Keyring::MAX_PLAINTEXT_BYTES) {
            throw SecretProtectionException::invalidValue();
        }
        try {
            return $this->keyring->encrypt($plaintext, $context->additionalAuthenticatedData($this->keyring->activeKeyId));
        } catch (RuntimeException) {
            throw SecretProtectionException::unavailable();
        }
    }

    public function reveal(
        #[\SensitiveParameter] string $ciphertext,
        #[\SensitiveParameter] string $nonce,
        string $keyId,
        SecretStorageContext $context,
    ): string {
        try {
            return $this->keyring->decrypt($ciphertext, $nonce, $keyId, $context->additionalAuthenticatedData($keyId));
        } catch (RuntimeException) {
            throw SecretProtectionException::unavailable();
        }
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
