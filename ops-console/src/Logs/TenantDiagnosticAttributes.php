<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Logs;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantContextRequirement;
use PeanutAdmin\Kernel\Tenancy\TenantScope;

/** Framework-neutral structured attribution for tenant-aware runtime diagnostics. */
final class TenantDiagnosticAttributes
{
    /** @return array{scope:string,tenant_id:int,correlation_id:string} */
    public static function fromScope(TenantScope $scope): array
    {
        return [
            'scope' => 'tenant',
            'tenant_id' => $scope->tenantId(),
            'correlation_id' => $scope->contextIdentity(),
        ];
    }

    /** @return array{scope:string,tenant_id:int|null,request_id:string} */
    public static function fromTenantContext(?TenantContext $context): array
    {
        if ($context === null) {
            return ['scope' => 'unavailable', 'tenant_id' => null, 'request_id' => ''];
        }

        return [
            'scope' => 'tenant',
            'tenant_id' => TenantContextRequirement::tenantId($context),
            'request_id' => $context->requestId,
        ];
    }
}
