<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use think\migration\Migrator;

final class CreatePaTenantMember extends Migrator
{
    protected const TABLE = 'pa_tenant_member';

    public function up(): void
    {
        $this->execute(KernelSchema::createSql(self::TABLE));
    }

    public function down(): void
    {
        $this->execute(KernelSchema::dropSql(self::TABLE));
    }
}
