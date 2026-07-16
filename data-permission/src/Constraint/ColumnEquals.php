<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Constraint;

final readonly class ColumnEquals implements QueryConstraint
{
    public function __construct(public ColumnReference $column, public int|string $value) {}
}
