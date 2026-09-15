<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy;

use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Persistence\Model\Tenant;

final class DefaultTenantContextResolver
{
    public function system(string $actor, string $operation, string $operationId): TenantSystemContext
    {
        $actor = trim($actor);
        $operation = trim($operation);
        $operationId = trim($operationId);
        if ($actor === '' || $operation === '' || $operationId === '') {
            throw new \DomainException('DEFAULT_TENANT_CONTEXT_UNAVAILABLE');
        }
        $ids = Tenant::where('code', 'default')->where('status', 'active')
            ->limit(2)->column('id');
        if (count($ids) !== 1 || (int) $ids[0] < 1) {
            throw new \DomainException('DEFAULT_TENANT_CONTEXT_UNAVAILABLE');
        }
        return new TenantSystemContext((int) $ids[0], $actor, $operation, $operationId);
    }

    public static function operationId(object $request): string
    {
        if (!method_exists($request, 'header')) {
            throw new \DomainException('DEFAULT_TENANT_CONTEXT_UNAVAILABLE');
        }
        $operationId = trim((string) $request->header('X-Request-Id', ''));
        return $operationId !== '' ? $operationId : bin2hex(random_bytes(16));
    }
}
