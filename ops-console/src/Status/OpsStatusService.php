<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Status;

use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\OpsConsole\Application\OpsConsoleException;
use PeanutAdmin\OpsConsole\Application\PlatformPermissionChecker;
use PeanutAdmin\OpsConsole\Package;
use Throwable;

final readonly class OpsStatusService
{
    public function __construct(
        private PlatformPermissionChecker $permissions,
        private RuntimeStatusProvider $provider,
    ) {}

    public function read(PlatformContext $context): OpsStatusSnapshot
    {
        if (!$this->permissions->allows($context, Package::READ_PERMISSION)) {
            throw OpsConsoleException::denied();
        }
        try {
            return $this->provider->snapshot($context);
        } catch (Throwable) {
            throw OpsConsoleException::statusUnavailable();
        }
    }
}
