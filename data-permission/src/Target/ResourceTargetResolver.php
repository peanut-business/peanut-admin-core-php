<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Target;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface ResourceTargetResolver
{
    public function resolveAndValidate(
        TenantContext $context,
        TypedResourceTargetSet $targets,
    ): ResolvedResourceTargets;
}
