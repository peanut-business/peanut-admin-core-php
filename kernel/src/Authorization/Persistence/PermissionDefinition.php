<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Persistence;

final readonly class PermissionDefinition
{
    public function __construct(
        public string $key,
        public string $moduleKey,
        public string $type,
        public string $name,
        public string $riskLevel,
        public string $manifestVersion,
    ) {}
}
