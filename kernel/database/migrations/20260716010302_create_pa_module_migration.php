<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Migration\ModuleSchema;
use think\migration\Migrator;

final class CreatePaModuleMigration extends Migrator
{
    public function up(): void
    {
        $this->execute(ModuleSchema::createSql('pa_module_migration'));
    }

    public function down(): void
    {
        $this->execute(ModuleSchema::dropSql('pa_module_migration'));
    }
}
