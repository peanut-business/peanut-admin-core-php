<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Constraint;

final readonly class ExistsByContract implements QueryConstraint
{
    public function __construct(
        public string $contractKey,
        public ColumnReference $outerColumn,
        public int $tenantId,
        public int $targetSetId,
    ) {}
}
