<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy;

final readonly class TenantRecord
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public TenantStatus $status,
        public int $securityRevision,
        public int $authorizationRevision,
        public int $revision,
    ) {}
}
