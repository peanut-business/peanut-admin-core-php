<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\External;

interface ExternalTenantAudit
{
    /** @param array<string, int|string> $attributes */
    public function record(string $outcome, array $attributes): void;
}
