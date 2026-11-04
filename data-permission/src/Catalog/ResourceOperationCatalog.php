<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Catalog;

use PeanutAdmin\Kernel\Authorization\Application\PageRequest;

interface ResourceOperationCatalog
{
    public function find(string $resourceKey, string $operation): ?ResourceOperation;

    /** Missing operations are omitted; keys retain the caller's resource keys.
     * @param array<string, string> $operations resource key => operation name
     * @return array<string, ResourceOperation>
     */
    public function findMany(array $operations): array;

    /** @return array{items: list<ResourceOperation>, total: int} */
    public function availableOperations(int $tenantId, PageRequest $page): array;

    public function moduleAvailable(int $tenantId, string $moduleKey): bool;

    public function registryRevision(): string;
}
