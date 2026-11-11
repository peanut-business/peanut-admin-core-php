<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Protection;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use Throwable;

/** Host-supplied deployment keys; no separate key store, permissions or persistence. */
final readonly class SodiumFieldProtector implements FieldProtector
{
    private const ALGORITHM = 'XCHACHA20-POLY1305-IETF';
    private const MAX_PLAINTEXT_BYTES = 4096;

    /** @param array<string, string> $keys key ID => raw 32-byte sodium key */
    public function __construct(#[\SensitiveParameter] private array $keys, private string $activeKeyId)
    {
        if ($keys === [] || !array_key_exists($activeKeyId, $keys)) {
            throw FieldProtectionException::unavailable();
        }
        foreach ($keys as $keyId => $key) {
            if (!is_int($keyId) && !is_string($keyId)) {
                throw FieldProtectionException::unavailable();
            }
            if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', (string) $keyId) !== 1
                || !is_string($key) || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw FieldProtectionException::unavailable();
            }
        }
    }

    public function protect(#[\SensitiveParameter] string $plaintext, FieldProtectionContext $field): array
    {
        if ($plaintext === '' || strlen($plaintext) > self::MAX_PLAINTEXT_BYTES) {
            throw FieldProtectionException::invalidValue();
        }
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $field->authenticatedData($this->activeKeyId),
                $nonce,
                $this->keys[$this->activeKeyId],
            );
        } catch (Throwable) {
            throw FieldProtectionException::unavailable();
        }

        return [
            'version' => 1,
            'algorithm' => self::ALGORITHM,
            'key_id' => $this->activeKeyId,
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    public function reveal(
        #[\SensitiveParameter] array $envelope,
        FieldProtectionContext $field,
        AuthorizedOperationContext $authorized,
        string $revealOperation,
    ): string {
        if ($revealOperation === '' || $authorized->tenantContext->tenantId !== $field->tenantId
            || $authorized->resourceKey !== $field->resourceKey
            || $authorized->operation !== $revealOperation) {
            throw FieldProtectionException::denied();
        }

        $permitted = false;
        foreach ($authorized->targets as $target) {
            if ($target->targetResourceKey === $field->resourceKey
                && in_array($field->resourceId, $target->targetIds, true)) {
                $permitted = true;
                break;
            }
        }
        if (!$permitted) {
            throw FieldProtectionException::denied();
        }

        return $this->decrypt($envelope, $field);
    }

    public function rotate(#[\SensitiveParameter] array $envelope, FieldProtectionContext $field): array
    {
        // Decrypt before producing a replacement. No writes happen inside Core.
        return $this->protect($this->decrypt($envelope, $field), $field);
    }

    /** @param array<string, mixed> $envelope */
    private function decrypt(#[\SensitiveParameter] array $envelope, FieldProtectionContext $field): string
    {
        if (count($envelope) !== 5
            || ($envelope['version'] ?? null) !== 1
            || ($envelope['algorithm'] ?? null) !== self::ALGORITHM
            || !is_string($envelope['key_id'] ?? null)
            || !is_string($envelope['nonce'] ?? null)
            || !is_string($envelope['ciphertext'] ?? null)
            || strlen($envelope['nonce']) !== 32
            || strlen($envelope['ciphertext']) > 5484) {
            throw FieldProtectionException::unavailable();
        }

        $keyId = $envelope['key_id'];
        $key = $this->keys[$keyId] ?? null;
        $nonce = base64_decode($envelope['nonce'], true);
        $ciphertext = base64_decode($envelope['ciphertext'], true);
        if (!is_string($key)
            || !is_string($nonce) || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            || !is_string($ciphertext)
            || strlen($ciphertext) < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES
            || strlen($ciphertext) > 8192) {
            throw FieldProtectionException::unavailable();
        }

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                $field->authenticatedData($keyId),
                $nonce,
                $key,
            );
        } catch (Throwable) {
            throw FieldProtectionException::unavailable();
        }

        if (!is_string($plaintext) || $plaintext === '' || strlen($plaintext) > self::MAX_PLAINTEXT_BYTES) {
            throw FieldProtectionException::unavailable();
        }

        return $plaintext;
    }
}
