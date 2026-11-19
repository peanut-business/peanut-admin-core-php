<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Protection;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use RuntimeException;

/** Host-supplied deployment keys; no separate key store, permissions or persistence. */
final readonly class SodiumFieldProtector implements FieldProtector
{
    private const ALGORITHM = 'XCHACHA20-POLY1305-IETF';
    private XChaCha20Keyring $keyring;

    /** @param array<string, string> $keys key ID => raw 32-byte sodium key */
    public function __construct(#[\SensitiveParameter] array $keys, string $activeKeyId)
    {
        try {
            $this->keyring = new XChaCha20Keyring($keys, $activeKeyId);
        } catch (RuntimeException) {
            throw FieldProtectionException::unavailable();
        }
    }

    public function protect(#[\SensitiveParameter] string $plaintext, FieldProtectionContext $field): array
    {
        if ($plaintext === '' || strlen($plaintext) > XChaCha20Keyring::MAX_PLAINTEXT_BYTES) {
            throw FieldProtectionException::invalidValue();
        }
        try {
            $sealed = $this->keyring->encrypt($plaintext, $field->authenticatedData($this->keyring->activeKeyId));
        } catch (RuntimeException) {
            throw FieldProtectionException::unavailable();
        }

        return [
            'version' => 1,
            'algorithm' => self::ALGORITHM,
            'key_id' => $sealed['key_id'],
            'nonce' => base64_encode($sealed['nonce']),
            'ciphertext' => base64_encode($sealed['ciphertext']),
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
        foreach ($authorized->targets as $target) {
            if ($target->targetResourceKey === $field->resourceKey
                && in_array($field->resourceId, $target->targetIds, true)) {
                return $this->decrypt($envelope, $field);
            }
        }
        throw FieldProtectionException::denied();
    }

    public function rotate(#[\SensitiveParameter] array $envelope, FieldProtectionContext $field): array
    {
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
        $nonce = base64_decode($envelope['nonce'], true);
        $ciphertext = base64_decode($envelope['ciphertext'], true);
        if (!is_string($nonce) || !is_string($ciphertext)) {
            throw FieldProtectionException::unavailable();
        }
        try {
            return $this->keyring->decrypt(
                $ciphertext,
                $nonce,
                $envelope['key_id'],
                $field->authenticatedData($envelope['key_id']),
            );
        } catch (RuntimeException) {
            throw FieldProtectionException::unavailable();
        }
    }
}
