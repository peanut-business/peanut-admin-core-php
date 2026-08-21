<?php

declare(strict_types=1);
use PeanutAdmin\Kernel\Authorization\Persistence\Schema\AuthorizationSchema;
use think\migration\Migrator;

final class CreatePaDataConditionDefinition extends Migrator
{
    public function up(): void
    {
        $this->execute(AuthorizationSchema::createSql('pa_data_condition_definition'));
    }
    public function down(): void
    {
        $this->execute(AuthorizationSchema::dropSql('pa_data_condition_definition'));
    }
}
