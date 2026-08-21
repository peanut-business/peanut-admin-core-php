<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Persistence;

final readonly class ResourceOperationDefinition
{
    public function __construct(
        public string $resourceKey,
        public string $operation,
        public string $accessMode,
        public string $targetCardinality,
        public string $permissionMatch,
        public string $auditLevel,
        public string $manifestDigest,
    ) {}
}
