<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Http;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\AuthorizationException;
use PeanutAdmin\Kernel\Authorization\PermissionRequirement;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;

final readonly class PermissionMiddleware
{
    public function __construct(
        private TenantAuthorizationEvaluator $tenant,
        private PlatformAuthorizationEvaluator $platform,
    ) {}

    public function authorizeTenant(TenantContext $context, PermissionRequirement $requirement): void
    {
        if ($requirement->audience !== 'tenant') {
            throw new AuthorizationException();
        }

        $this->assertMatch(
            $requirement,
            fn(string $permission): bool => $this->tenant->allows($context, $permission),
        );
    }

    public function authorizePlatform(PlatformContext $context, PermissionRequirement $requirement): void
    {
        if ($requirement->audience !== 'platform') {
            throw new AuthorizationException();
        }

        $this->assertMatch(
            $requirement,
            fn(string $permission): bool => $this->platform->allows($context, $permission),
        );
    }

    /** @param callable(string): bool $allows */
    private function assertMatch(PermissionRequirement $requirement, callable $allows): void
    {
        $results = array_map($allows, $requirement->permissionKeys);
        $authorized = $requirement->match === 'all'
            ? !in_array(false, $results, true)
            : in_array(true, $results, true);

        if (!$authorized) {
            throw new AuthorizationException();
        }
    }
}
