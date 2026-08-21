<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole;

final class Package
{
    public const VERSION = '0.1.0';

    public const READ_PERMISSION = 'platform.ops.read';

    public const BACKUP_PERMISSION = 'platform.ops.backup.manage';

    public const RESTORE_PERMISSION = 'platform.ops.restore.manage';

    public const MAINTENANCE_PERMISSION = 'platform.ops.maintenance.manage';

    public const LOGS_PERMISSION = 'platform.ops.logs.read';

    public const BACKUP_TASK_TYPE = 'ops.backup.create';

    public const RESTORE_TASK_TYPE = 'ops.restore.verify';

    private function __construct() {}
}
