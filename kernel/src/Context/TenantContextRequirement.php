<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Context;

use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Auth\TenantContext;

final class TenantContextRequirement
{
    public static function tenantId(mixed $context): int
    {
        if (!$context instanceof TenantContext
            || $context->tenantId < 1
            || $context->accountId < 1
            || $context->memberId < 1
            || $context->authorizationRevision < 1
            || $context->sessionKey === ''
            || $context->clientKey === ''
            || $context->requestId === '') {
            throw new AuthException('CONTEXT_TENANT_REQUIRED', 403);
        }

        return $context->tenantId;
    }

    public static function fromRequest(object $request): TenantContext
    {
        $context = $request->tenantContext ?? null;
        self::tenantId($context);

        return $context;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public static function withoutTenantId(array $payload): array
    {
        unset($payload['tenant_id']);

        return $payload;
    }
}
