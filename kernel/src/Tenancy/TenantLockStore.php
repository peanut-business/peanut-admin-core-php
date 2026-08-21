<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy;

interface TenantLockStore
{
    public function acquire(TenantScope $scope, string $resourceKey): bool;

    public function release(TenantScope $scope, string $resourceKey): void;
}
