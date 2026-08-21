<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Constraint;

final readonly class CompiledQueryConstraint
{
    /** @param array<string, int|string> $parameters */
    public function __construct(public string $sql, public array $parameters) {}
}
