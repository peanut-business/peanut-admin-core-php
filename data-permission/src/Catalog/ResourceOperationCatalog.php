<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Catalog;

use PeanutAdmin\Kernel\Authorization\Application\PageRequest;

interface ResourceOperationCatalog
{
    public function find(string $resourceKey, string $operation): ?ResourceOperation;

    /** @return array{items: list<ResourceOperation>, total: int} */
    public function availableOperations(int $tenantId, PageRequest $page): array;

    public function moduleAvailable(int $tenantId, string $moduleKey): bool;

    public function registryRevision(): string;
}
