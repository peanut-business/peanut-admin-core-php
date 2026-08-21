<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use think\migration\Migrator;

final class AddPaTenantMemberDepartmentForeignKey extends Migrator
{
    public function up(): void
    {
        $this->execute(KernelSchema::addTenantMemberDepartmentForeignKeySql());
    }

    public function down(): void
    {
        $this->execute(KernelSchema::dropTenantMemberDepartmentForeignKeySql());
    }
}
