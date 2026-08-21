<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\External;

readonly class ExternalTenantBinding
{
    /** @param array<string, mixed> $config */
    public function __construct(
        public int $id,
        public int $tenantId,
        public string $provider,
        public string $callbackKey,
        public string $identityHash,
        public string $identityHint,
        public array $config,
        public bool $active,
        public bool $tenantActive,
    ) {}
}
