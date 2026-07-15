<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

final readonly class TenantAuthentication
{
    public function __construct(
        public TenantTokenPair $tokens,
        public TenantContext $context,
    ) {}

    /** @return array<string, mixed> */
    public function responseData(): array
    {
        return [
            'state' => 'authenticated',
            'access_token' => $this->tokens->access->expose(),
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'context' => [
                'audience' => 'tenant',
                'account_id' => (string) $this->context->accountId,
                'tenant_id' => (string) $this->context->tenantId,
                'tenant_member_id' => (string) $this->context->memberId,
            ],
        ];
    }
}
