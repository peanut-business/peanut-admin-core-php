<?php

declare(strict_types=1);
use PeanutAdmin\Kernel\Authorization\Persistence\Schema\AuthorizationSchema;
use think\migration\Migrator;

final class CreatePaResourceOperationPermission extends Migrator
{
    public function up(): void
    {
        $this->execute(AuthorizationSchema::createSql('pa_resource_operation_permission'));
    }
    public function down(): void
    {
        $this->execute(AuthorizationSchema::dropSql('pa_resource_operation_permission'));
    }
}
