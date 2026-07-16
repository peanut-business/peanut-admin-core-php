<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Constraint\QueryConstraint;
use PeanutAdmin\DataPermission\Context\AuthorizationContext;
use PeanutAdmin\DataPermission\Policy\EffectiveCondition;

interface ConditionProvider
{
    public function compile(
        AuthorizationContext $context,
        ResourceOperation $operation,
        EffectiveCondition $condition,
        ProviderColumnMap $columns,
    ): QueryConstraint;
}
