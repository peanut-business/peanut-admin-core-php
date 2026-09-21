<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Scope;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Implemented by the IAM owner; callers cannot construct authority from request grants. */
interface ReadScopeAuthority
{
    /** @param list<int> $requestedSourceTenantIds */
    public function authorize(
        TenantContext $actor,
        string $capability,
        string $action,
        array $requestedSourceTenantIds = [],
    ): AuthorizedReadScope;

    /** Recheck module, permission and grant revisions before a batch or result delivery. */
    public function assertCurrent(AuthorizedReadScope $scope): void;
}
