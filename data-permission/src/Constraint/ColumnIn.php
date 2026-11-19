<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Constraint;

final readonly class ColumnIn implements QueryConstraint
{
    /** @param non-empty-list<int|string> $values */
    public function __construct(public ColumnReference $column, public array $values) {}
}
