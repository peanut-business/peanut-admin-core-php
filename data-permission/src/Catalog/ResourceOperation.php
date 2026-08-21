<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Catalog;

final readonly class ResourceOperation
{
    /**
     * @param list<string> $permissionKeys
     * @param list<OperationTargetType> $targetTypes
     */
    public function __construct(
        public int $id,
        public int $protectedResourceId,
        public string $resourceKey,
        public string $moduleKey,
        public string $providerKey,
        public string $ownership,
        public string $operation,
        public string $accessMode,
        public string $targetCardinality,
        public string $permissionMatch,
        public array $permissionKeys,
        public array $targetTypes,
    ) {}
}
