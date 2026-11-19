<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Constraint\QueryConstraint;
use PeanutAdmin\DataPermission\Context\AuthorizationContext;
use PeanutAdmin\DataPermission\Decision\AuthorizationDecision;
use PeanutAdmin\DataPermission\Policy\EffectivePolicySet;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;

interface ResourcePolicyProvider
{
    public function tenantConstraint(
        AuthorizationContext $context,
        ResourceOperation $operation,
    ): QueryConstraint;

    public function requestedTargetConstraint(
        AuthorizationContext $context,
        ResourceOperation $operation,
        TypedResourceTargetCollection $targets,
    ): QueryConstraint;

    public function compilePredicate(
        AuthorizationContext $context,
        ResourceOperation $operation,
        EffectivePolicySet $policies,
    ): QueryConstraint;

    public function assertTargetsAllowed(
        AuthorizationContext $context,
        ResourceOperation $operation,
        TypedResourceTargetCollection $targets,
        EffectivePolicySet $policies,
    ): AuthorizationDecision;

    public function assertCreateAllowed(
        AuthorizationContext $context,
        ResourceOperation $operation,
        TypedResourceTargetCollection $targets,
        EffectivePolicySet $policies,
    ): AuthorizationDecision;
}
