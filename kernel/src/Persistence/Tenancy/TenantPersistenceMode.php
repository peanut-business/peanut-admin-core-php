<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Tenancy;

enum TenantPersistenceMode: string
{
    case TenantScoped = 'tenant-scoped';
    case InstanceScoped = 'instance-scoped';

    public function usesTenantColumn(): bool
    {
        return $this === self::TenantScoped;
    }
}
