<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Idempotency\IdempotencySchema;
use think\migration\Migrator;

final class CreatePaPlatformIdempotencyRecord extends Migrator
{
    public function up(): void
    {
        $this->execute(IdempotencySchema::platform());
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `pa_platform_idempotency_record`');
    }
}
