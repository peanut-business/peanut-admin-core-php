<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Secret;

interface SecretProtector
{
    /** @return array{ciphertext: string, nonce: string, key_id: string} */
    public function protect(string $plaintext, SecretStorageContext $context): array;

    public function reveal(
        string $ciphertext,
        string $nonce,
        string $keyId,
        SecretStorageContext $context,
    ): string;
}
