<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform\Authorization;

use PeanutAdmin\Kernel\Authorization\AuthorizationException;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Context\PlatformContext;

final readonly class PlatformAuthorizationEvaluator
{
    public function __construct(
        private PlatformAuthorizationRepository $repository,
        private RevisionPermissionCache $cache,
    ) {}

    public function allows(PlatformContext $context, string $permissionKey): bool
    {
        if (!str_starts_with($permissionKey, 'platform.')) {
            return false;
        }

        $revision = $this->repository->revision($context->operatorId);
        $principalKey = "operator:{$context->operatorId}";
        $permissions = $this->cache->get('platform', $principalKey, $revision);
        if ($permissions === null) {
            $permissions = $this->repository->permissions($context->operatorId);
            $this->cache->put('platform', $principalKey, $revision, $permissions);
        }

        return $permissions->allows($permissionKey);
    }

    public function assertAllowed(PlatformContext $context, string $permissionKey): void
    {
        if (!$this->allows($context, $permissionKey)) {
            throw new AuthorizationException();
        }
    }
}
