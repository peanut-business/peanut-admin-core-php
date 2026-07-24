<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Crypto;

interface WebhookSecretProtector
{
    /** @return array{ciphertext: string, key_id: string} */
    public function seal(string $secret, string $binding): array;

    public function open(string $ciphertext, string $keyId, string $binding): string;
}
