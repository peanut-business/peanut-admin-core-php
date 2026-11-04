<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Constraint;

final readonly class JsonArrayContainsColumn implements QueryConstraint
{
    /** @param non-empty-list<string> $values IDs normalized by TypedResourceTargetSet. */
    public function __construct(public ColumnReference $column, public array $values) {}
}
