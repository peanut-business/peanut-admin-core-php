<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Migration\ModuleSchema;
use think\migration\Migrator;

final class CreatePaMenuDefinition extends Migrator
{
    public function up(): void
    {
        $this->execute(ModuleSchema::createSql('pa_menu_definition'));
    }

    public function down(): void
    {
        $this->execute(ModuleSchema::dropSql('pa_menu_definition'));
    }
}
