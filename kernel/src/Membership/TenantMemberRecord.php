<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Membership;

final readonly class TenantMemberRecord
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $accountId,
        public TenantMemberStatus $status,
        public int $securityRevision,
        public int $authorizationRevision,
    ) {}
}
