<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Constraint;

interface QueryConstraintCompiler
{
    public function compile(QueryConstraint $constraint): CompiledQueryConstraint;
}
