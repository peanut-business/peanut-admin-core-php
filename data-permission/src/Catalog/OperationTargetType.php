<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Catalog;

final readonly class OperationTargetType
{
    public function __construct(
        public string $targetRole,
        public string $targetResourceKey,
        public string $resolverKey,
        public string $catalogProviderKey,
        public string $inputMode,
        public ?string $policySelectionPermissionKey,
    ) {}
}
