<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Target;

use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Context\AuthorizationContext;
use PeanutAdmin\DataPermission\Policy\EffectivePolicySet;

interface ResourceTargetCatalogProvider
{
    public function searchAllowedTargets(
        AuthorizationContext $context,
        ResourceOperation $operation,
        TargetCatalogQuery $query,
        EffectivePolicySet $policies,
    ): TargetOptionPage;
}
