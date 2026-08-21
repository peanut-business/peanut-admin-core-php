<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Idempotency\IdempotencySchema;
use think\migration\Migrator;

final class CreatePaTenantIdempotencyRecord extends Migrator
{
    public function up(): void
    {
        $this->execute(IdempotencySchema::tenant());
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `pa_tenant_idempotency_record`');
    }
}
