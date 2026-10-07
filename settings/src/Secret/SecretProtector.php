<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Secret;

/** Protects setting values with authenticated encryption bound to their storage context. */
interface SecretProtector
{
    /**
     * Returns the protected payload; plaintext must never be stored alongside it.
     *
     * @return array{ciphertext: string, nonce: string, key_id: string}
     */
    public function protect(string $plaintext, SecretStorageContext $context): array;

    /** Reveals a payload only with the same context used to protect it. */
    public function reveal(
        string $ciphertext,
        string $nonce,
        string $keyId,
        SecretStorageContext $context,
    ): string;
}
