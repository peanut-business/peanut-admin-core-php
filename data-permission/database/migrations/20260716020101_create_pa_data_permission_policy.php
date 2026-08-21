<?php

declare(strict_types=1);
use PeanutAdmin\DataPermission\Persistence\Schema\DataPermissionSchema;
use think\migration\Migrator;

final class CreatePaDataPermissionPolicy extends Migrator
{
    public function up(): void
    {
        $this->execute(DataPermissionSchema::createSql('pa_data_permission_policy'));
    }
    public function down(): void
    {
        $this->execute(DataPermissionSchema::dropSql('pa_data_permission_policy'));
    }
}
