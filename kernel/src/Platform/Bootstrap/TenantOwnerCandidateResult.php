<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform\Bootstrap;

final readonly class TenantOwnerCandidateResult
{
    public function __construct(
        public int $tenantId,
        public int $accountId,
        public int $memberId,
        public int $roleId,
    ) {}

    /** @return array{tenant_id: int, account_id: int, member_id: int, role_id: int, status: string} */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'account_id' => $this->accountId,
            'member_id' => $this->memberId,
            'role_id' => $this->roleId,
            'status' => 'pending',
        ];
    }
}
