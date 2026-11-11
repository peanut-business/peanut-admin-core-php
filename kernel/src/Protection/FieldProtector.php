<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Protection;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

/**
 * Business-field cryptography, not a Settings secret or a permission evaluator.
 * The caller authorizes the exact operation and target through existing policies.
 * Fields and envelope persistence remain the owning application's responsibility.
 */
interface FieldProtector
{
    /** @return array{version: int, algorithm: string, key_id: string, nonce: string, ciphertext: string} */
    public function protect(#[\SensitiveParameter] string $plaintext, FieldProtectionContext $field): array;

    /** @param array<string, mixed> $envelope */
    public function reveal(
        #[\SensitiveParameter] array $envelope,
        FieldProtectionContext $field,
        AuthorizedOperationContext $authorized,
        string $revealOperation,
    ): string;

    /**
     * In-memory re-encryption only; persist atomically and keep old keys until success.
     * @param array<string, mixed> $envelope
     * @return array{version: int, algorithm: string, key_id: string, nonce: string, ciphertext: string}
     */
    public function rotate(#[\SensitiveParameter] array $envelope, FieldProtectionContext $field): array;
}
