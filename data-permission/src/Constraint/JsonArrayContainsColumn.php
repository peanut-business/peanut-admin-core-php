<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Constraint;

use InvalidArgumentException;

final readonly class JsonArrayContainsColumn implements QueryConstraint
{
    /** @var non-empty-list<string> */
    public array $values;

    /** @param list<string> $values */
    public function __construct(public ColumnReference $column, array $values)
    {
        $normalized = array_values(array_unique(array_map('strval', $values), SORT_STRING));
        if ($normalized === []) {
            throw new InvalidArgumentException('JSON target constraint values cannot be empty.');
        }
        $this->values = $normalized;
    }
}
