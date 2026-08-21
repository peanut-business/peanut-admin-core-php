<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use DateTimeImmutable;

final readonly class TenantModuleRecord
{
    public function __construct(
        public int $tenantId,
        public string $moduleKey,
        public string $status,
        public ?DateTimeImmutable $effectiveAt,
        public ?DateTimeImmutable $expiresAt,
        public int $authorizationRevision,
    ) {}

    public function isEffective(DateTimeImmutable $now): bool
    {
        return $this->status === 'enabled'
            && ($this->effectiveAt === null || $this->effectiveAt <= $now)
            && ($this->expiresAt === null || $now < $this->expiresAt);
    }
}
