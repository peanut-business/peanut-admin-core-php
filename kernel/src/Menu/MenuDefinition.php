<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Menu;

final readonly class MenuDefinition
{
    /** @param list<string> $clientKeys */
    public function __construct(
        public string $key,
        public string $moduleKey,
        public string $scope,
        public ?string $parentKey,
        public string $type,
        public string $name,
        public ?string $routeName,
        public ?string $routePath,
        public ?string $componentKey,
        public ?string $requiredPermission,
        public array $clientKeys,
        public int $sortOrder = 0,
        public ?string $icon = null,
    ) {}
}
