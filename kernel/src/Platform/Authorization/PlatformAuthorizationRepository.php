<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform\Authorization;

use PeanutAdmin\Kernel\Authorization\EffectivePermissionSet;

interface PlatformAuthorizationRepository
{
    public function revision(int $operatorId): string;

    public function permissions(int $operatorId): EffectivePermissionSet;
}
