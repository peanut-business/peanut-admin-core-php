<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Constraint;

use InvalidArgumentException;

final readonly class ColumnIn implements QueryConstraint
{
    /** @param non-empty-list<int|string> $values */
    public function __construct(public ColumnReference $column, public array $values)
    {
        if (count($values) > 500) {
            throw new InvalidArgumentException('ColumnIn is limited to 500 values; use an EXISTS contract.');
        }
    }
}
