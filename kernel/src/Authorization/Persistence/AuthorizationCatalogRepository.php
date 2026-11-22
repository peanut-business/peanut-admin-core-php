<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Persistence;

interface AuthorizationCatalogRepository
{
    public function syncPermission(PermissionDefinition $definition): int;

    public function permissionId(string $key): int;

    public function registryRevision(): string;
}
