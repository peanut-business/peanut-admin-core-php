<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Status;

use PeanutAdmin\Kernel\Context\PlatformContext;

interface RuntimeStatusProvider
{
    public function snapshot(PlatformContext $context): OpsStatusSnapshot;
}
