<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Catalog;

interface ResourceOperationCatalog
{
    public function find(string $resourceKey, string $operation): ?ResourceOperation;

    public function moduleAvailable(int $tenantId, string $moduleKey): bool;

    public function registryRevision(): string;
}
