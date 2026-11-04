<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Constraint;

/** A bound exclusion set used to preserve explicit object denies. */
final readonly class ColumnNotIn implements QueryConstraint
{
    /** @param non-empty-list<int> $values Exclusions validated by SourceReadScope (at most 500). */
    public function __construct(public ColumnReference $column, public array $values) {}
}
