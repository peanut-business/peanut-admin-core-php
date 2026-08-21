<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

use PeanutAdmin\Kernel\Auth\TenantContext;

final readonly class TenantAuthorizationEvaluator
{
    public function __construct(
        private TenantAuthorizationRepository $repository,
        private RevisionPermissionCache $cache,
    ) {}

    public function allows(TenantContext $context, string $permissionKey): bool
    {
        if (str_starts_with($permissionKey, 'platform.')) {
            return false;
        }

        $revision = $this->repository->revision($context->tenantId, $context->memberId);
        $principalKey = "tenant:{$context->tenantId}:member:{$context->memberId}";
        $permissions = $this->cache->get('tenant', $principalKey, $revision);
        if ($permissions === null) {
            $permissions = $this->repository->permissions($context->tenantId, $context->memberId);
            $this->cache->put('tenant', $principalKey, $revision, $permissions);
        }

        return $permissions->allows($permissionKey);
    }

    public function assertAllowed(TenantContext $context, string $permissionKey): void
    {
        if (!$this->allows($context, $permissionKey)) {
            throw new AuthorizationException();
        }
    }
}
