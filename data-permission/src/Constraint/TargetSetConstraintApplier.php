<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Constraint;

use think\db\BaseQuery;

/** The access module owns target-set storage; Core owns the surrounding constraint AST. */
interface TargetSetConstraintApplier
{
    public function apply(BaseQuery $query, ExistsByContract $constraint): void;
}
