<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Constraint\QueryConstraint;
use PeanutAdmin\DataPermission\Context\AuthorizationContext;
use PeanutAdmin\DataPermission\Policy\EffectivePolicySet;

interface ResourceQueryPolicyProvider
{
    public function tenantConstraint(
        AuthorizationContext $context,
        ResourceOperation $operation,
    ): QueryConstraint;

    public function compilePredicate(
        AuthorizationContext $context,
        ResourceOperation $operation,
        EffectivePolicySet $policies,
    ): QueryConstraint;
}
