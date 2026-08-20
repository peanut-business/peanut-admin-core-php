<?php
declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy;

final readonly class TenantLockNamespace
{
    public function __construct(private TenantScope $scope) {}

    public function name(string $logicalSeed): string
    {
        return TenantNamespace::lockName($this->scope, $logicalSeed);
    }
}
