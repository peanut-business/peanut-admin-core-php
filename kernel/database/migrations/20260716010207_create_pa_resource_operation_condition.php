<?php

declare(strict_types=1);
use PeanutAdmin\Kernel\Authorization\Persistence\Schema\AuthorizationSchema;
use think\migration\Migrator;

final class CreatePaResourceOperationCondition extends Migrator
{
    public function up(): void
    {
        $this->execute(AuthorizationSchema::createSql('pa_resource_operation_condition'));
    }
    public function down(): void
    {
        $this->execute(AuthorizationSchema::dropSql('pa_resource_operation_condition'));
    }
}
