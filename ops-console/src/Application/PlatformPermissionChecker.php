<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Application;

use PeanutAdmin\Kernel\Context\PlatformContext;

interface PlatformPermissionChecker
{
    public function allows(PlatformContext $context, string $permissionKey): bool;
}
