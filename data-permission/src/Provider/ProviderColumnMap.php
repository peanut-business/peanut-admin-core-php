<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

use PeanutAdmin\DataPermission\Constraint\ColumnReference;

final readonly class ProviderColumnMap
{
    /** @param array<string, ColumnReference> $targetColumns */
    public function __construct(
        public ColumnReference $tenantColumn,
        public ?ColumnReference $selfColumn = null,
        public ?ColumnReference $departmentColumn = null,
        public array $targetColumns = [],
    ) {}
}
