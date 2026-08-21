<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Constraint;

final readonly class OrConstraint implements QueryConstraint
{
    /** @param non-empty-list<QueryConstraint> $constraints */
    public function __construct(public array $constraints) {}
}
