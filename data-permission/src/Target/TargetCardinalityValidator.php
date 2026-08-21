<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Target;

use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;

final class TargetCardinalityValidator
{
    public function validate(ResourceOperation $operation, TypedResourceTargetCollection $targets): void
    {
        $primaryCount = $targets->countForRole('primary');
        $valid = match ($operation->targetCardinality) {
            'none' => $targets->sets === [],
            'one_required' => $primaryCount === 1,
            'zero_or_one' => $primaryCount <= 1,
            'many_readable', 'aggregate_read' => true,
            'policy_publish' => $primaryCount >= 1,
            'bulk_write' => false,
            default => false,
        };
        if (!$valid) {
            throw new DataAuthorizationException(
                'AUTHZ_TARGET_CARDINALITY_INVALID',
                'The requested targets do not satisfy the operation cardinality.',
            );
        }
    }
}
