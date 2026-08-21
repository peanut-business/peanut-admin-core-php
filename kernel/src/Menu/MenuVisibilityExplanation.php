<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Menu;

final readonly class MenuVisibilityExplanation
{
    public function __construct(
        public string $menuKey,
        public bool $visible,
        public string $reason,
        public ?string $trustedRoutePath,
        public ?string $icon,
    ) {}
}
