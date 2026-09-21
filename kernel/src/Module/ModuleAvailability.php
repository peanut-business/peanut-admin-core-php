<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Tenancy\TenantScope;

/** Authoritative installation and tenant entitlement checks supplied by the host. */
interface ModuleAvailability
{
    public function assertAvailable(TenantScope $scope, string $moduleKey, DateTimeImmutable $now, bool $lock = false): void;

    public function assertDeployment(string $moduleKey, bool $lock = false): void;

    public function assertTenant(TenantScope $scope, string $moduleKey, DateTimeImmutable $now, bool $lock = false): void;
}
