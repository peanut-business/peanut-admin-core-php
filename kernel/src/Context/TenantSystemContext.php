<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Context;

final readonly class TenantSystemContext
{
    public function __construct(
        public int $tenantId,
        public string $actorKey,
        public string $operation,
        public string $operationId,
    ) {}
}
