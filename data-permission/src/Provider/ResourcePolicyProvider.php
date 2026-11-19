<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

/**
 * Unified resource policy provider contract combining query, target, and create authorization.
 */
interface ResourcePolicyProvider extends
    ResourceQueryPolicyProvider,
    ResourceTargetPolicyProvider,
    ResourceCreatePolicyProvider
{
}
