<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Target;

final readonly class TargetOptionPage
{
    /** @param list<array{id: string, label: string}> $items */
    public function __construct(public array $items, public int $total) {}
}
