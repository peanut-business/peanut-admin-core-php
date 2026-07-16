<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Target;

final readonly class ResolvedResourceTargets
{
    public function __construct(public TypedResourceTargetCollection $targets) {}
}
