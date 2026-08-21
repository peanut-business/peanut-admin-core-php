<?php

declare(strict_types=1);

use think\migration\Migrator;

final class AddZeroOrOneCardinality extends Migrator
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE `pa_resource_operation`
DROP CHECK `chk_resource_operation_cardinality`,
ADD CONSTRAINT `chk_resource_operation_cardinality`
CHECK (`target_cardinality` IN ('none', 'one_required', 'zero_or_one', 'many_readable', 'aggregate_read', 'policy_publish', 'bulk_write'))
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE `pa_resource_operation`
DROP CHECK `chk_resource_operation_cardinality`,
ADD CONSTRAINT `chk_resource_operation_cardinality`
CHECK (`target_cardinality` IN ('none', 'one_required', 'many_readable', 'aggregate_read', 'policy_publish', 'bulk_write'))
SQL);
    }
}
