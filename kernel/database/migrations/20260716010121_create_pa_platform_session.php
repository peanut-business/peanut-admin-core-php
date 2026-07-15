<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use think\migration\Migrator;

final class CreatePaPlatformSession extends Migrator
{
    protected const TABLE = 'pa_platform_session';

    public function up(): void
    {
        $this->execute(KernelSchema::createSql(self::TABLE));
    }

    public function down(): void
    {
        $this->execute(KernelSchema::dropSql(self::TABLE));
    }
}
