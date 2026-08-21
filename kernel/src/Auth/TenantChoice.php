<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

final readonly class TenantChoice
{
    public function __construct(
        public int $tenantId,
        public string $tenantCode,
        public string $tenantName,
        public int $memberId,
        public string $memberDisplayName,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'tenant_id' => (string) $this->tenantId,
            'tenant_code' => $this->tenantCode,
            'tenant_name' => $this->tenantName,
            'tenant_member_id' => (string) $this->memberId,
            'member_display_name' => $this->memberDisplayName,
        ];
    }
}
