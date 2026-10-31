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
        return $this->allowsMany($context, [$permissionKey])[$permissionKey];
    }

    /** One independent projection; no permission state is retained between calls.
     * @param list<string> $permissionKeys
     * @return array<string, bool>
     */
    public function allowsMany(TenantContext $context, array $permissionKeys): array
    {
        $results = array_fill_keys($permissionKeys, false);
        $tenantKeys = array_values(array_filter($permissionKeys, static fn(string $key): bool => !str_starts_with($key, 'platform.')));
        if ($tenantKeys === []) {
            return $results;
        }
        $revision = $this->repository->revision($context->tenantId, $context->memberId);
        $principalKey = "tenant:{$context->tenantId}:member:{$context->memberId}";
        $permissions = $this->cache->get('tenant', $principalKey, $revision);
        if ($permissions === null) {
            $permissions = $this->repository->permissions($context->tenantId, $context->memberId);
            $this->cache->put('tenant', $principalKey, $revision, $permissions);
        }
        foreach ($tenantKeys as $key) {
            $results[$key] = $permissions->allows($key);
        }
        return $results;
    }

    public function assertAllowed(TenantContext $context, string $permissionKey): void
    {
        if (!$this->allows($context, $permissionKey)) {
            throw new AuthorizationException();
        }
    }
}
