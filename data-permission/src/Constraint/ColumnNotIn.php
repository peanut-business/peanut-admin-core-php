<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Constraint;

/** A bound exclusion set used to preserve explicit object denies. */
final readonly class ColumnNotIn implements QueryConstraint
{
    /** @param non-empty-list<int|string> $values */
    public function __construct(public ColumnReference $column, public array $values)
    {
        if ($values === [] || count($values) > 500 || !array_is_list($values)) {
            throw new \InvalidArgumentException('An exclusion set must contain between one and 500 values.');
        }
        foreach ($values as $value) {
            if (!is_int($value) && !is_string($value)) {
                throw new \InvalidArgumentException('An exclusion value must be an integer or string.');
            }
        }
    }
}
