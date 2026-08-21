<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Membership;

final readonly class TenantRoleRecord
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public string $key,
        public bool $isBuiltin,
    ) {}
}
