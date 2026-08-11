<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Tests\Unit\Database;

use InvalidArgumentException;
use PeanutAdmin\Workflow\Database\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testOwnsExactlyTheFiveContractTablesInForeignKeyOrder(): void
    {
        self::assertSame([
            'pa_workflow_definition',
            'pa_workflow_definition_version',
            'pa_workflow_instance',
            'pa_workflow_work_item',
            'pa_workflow_event',
        ], Schema::tableNames());
        $sql = implode("\n", Schema::createSql());
        foreach ([
            'uk_workflow_definition_identity',
            'uk_workflow_definition_version_digest',
            'uk_workflow_instance_active_subject',
            'uk_workflow_work_item_pending_assignee',
            'uk_workflow_event_sequence',
            'ON DELETE RESTRICT',
        ] as $contract) {
            self::assertStringContainsString($contract, $sql);
        }
    }

    public function testCreateSqlIsDeterministicAndDeclaresIdempotentReentry(): void
    {
        self::assertSame(Schema::createSql(), Schema::createSql());
        self::assertCount(5, Schema::createSql());
        foreach (Schema::tableNames() as $table) {
            self::assertStringStartsWith(
                "CREATE TABLE IF NOT EXISTS `{$table}`",
                Schema::createTableSql($table),
            );
        }
    }

    public function testDeclaresTheExactCheckConstraintCorpus(): void
    {
        self::assertSame([
            "CONSTRAINT `chk_workflow_definition_module_key` CHECK (`module_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$')",
            "CONSTRAINT `chk_workflow_definition_workflow_key` CHECK (`workflow_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$')",
            "CONSTRAINT `chk_workflow_definition_status` CHECK (`status` IN ('draft', 'active', 'retired'))",
            "CONSTRAINT `chk_workflow_definition_digest` CHECK (`draft_graph_sha256` REGEXP '^[0-9a-f]{64}$')",
            'CONSTRAINT `chk_workflow_definition_revision` CHECK (`revision` >= 1)',
            "CONSTRAINT `chk_workflow_definition_retired_shape` CHECK ((`status` = 'retired' AND `retired_at` IS NOT NULL) OR (`status` <> 'retired' AND `retired_at` IS NULL))",
            'CONSTRAINT `chk_workflow_definition_version_number` CHECK (`version` >= 1)',
            "CONSTRAINT `chk_workflow_definition_version_digest` CHECK (`graph_sha256` REGEXP '^[0-9a-f]{64}$')",
            "CONSTRAINT `chk_workflow_instance_key` CHECK (`instance_key` REGEXP '^instance_[0-9a-f]{32}$')",
            'CONSTRAINT `chk_workflow_instance_version` CHECK (`definition_version` >= 1)',
            'CONSTRAINT `chk_workflow_instance_revision` CHECK (`revision` >= 1)',
            "CONSTRAINT `chk_workflow_instance_digest` CHECK (`subject_revision_sha256` REGEXP '^[0-9a-f]{64}$')",
            "CONSTRAINT `chk_workflow_instance_status` CHECK (`status` IN ('active', 'completed', 'cancelled'))",
            "CONSTRAINT `chk_workflow_instance_terminal_shape` CHECK ((`status` = 'active' AND `completed_at` IS NULL AND `cancelled_at` IS NULL) OR (`status` = 'completed' AND `completed_at` IS NOT NULL AND `cancelled_at` IS NULL) OR (`status` = 'cancelled' AND `completed_at` IS NULL AND `cancelled_at` IS NOT NULL))",
            "CONSTRAINT `chk_workflow_work_item_key` CHECK (`work_item_key` REGEXP '^work_[0-9a-f]{32}$')",
            'CONSTRAINT `chk_workflow_work_item_round` CHECK (`round_no` >= 1)',
            'CONSTRAINT `chk_workflow_work_item_revision` CHECK (`revision` >= 1)',
            "CONSTRAINT `chk_workflow_work_item_source` CHECK (`assignment_source_kind` IN ('member', 'role', 'department', 'initiator', 'previous_actor'))",
            "CONSTRAINT `chk_workflow_work_item_status` CHECK (`status` IN ('pending', 'completed', 'cancelled'))",
            "CONSTRAINT `chk_workflow_work_item_terminal_shape` CHECK ((`status` = 'pending' AND `decision` IS NULL AND `completed_by_member_id` IS NULL AND `completed_at` IS NULL AND `cancelled_at` IS NULL) OR (`status` = 'completed' AND `decision` IS NOT NULL AND `completed_by_member_id` IS NOT NULL AND `completed_at` IS NOT NULL AND `cancelled_at` IS NULL) OR (`status` = 'cancelled' AND `decision` IS NULL AND `completed_by_member_id` IS NULL AND `completed_at` IS NULL AND `cancelled_at` IS NOT NULL))",
            'CONSTRAINT `chk_workflow_event_sequence` CHECK (`sequence_no` >= 1)',
            "CONSTRAINT `chk_workflow_event_key` CHECK (`event_key` REGEXP '^tenant\\\\.workflow\\\\.[a-z_]+$')",
            "CONSTRAINT `chk_workflow_event_subject_digest` CHECK (`subject_revision_sha256` REGEXP '^[0-9a-f]{64}$')",
            "CONSTRAINT `chk_workflow_event_comment_shape` CHECK ((`comment_text` IS NULL AND `comment_sha256` IS NULL) OR (`comment_text` IS NOT NULL AND `comment_sha256` REGEXP '^[0-9a-f]{64}$'))",
            "CONSTRAINT `chk_workflow_event_actor_shape` CHECK ((`actor_type` = 'member' AND `actor_member_id` IS NOT NULL) OR (`actor_type` = 'tenant_system' AND `actor_member_id` IS NULL))",
        ], $this->checkConstraintCorpus());
    }

    public function testDropSqlIsIsolatedAndUnknownTablesFail(): void
    {
        self::assertSame('DROP TABLE IF EXISTS `pa_workflow_event`', Schema::dropSql('pa_workflow_event'));
        $this->expectException(InvalidArgumentException::class);
        Schema::createTableSql('pa_tenant');
    }

    /** @return list<string> */
    private function checkConstraintCorpus(): array
    {
        $constraints = [];
        foreach (Schema::createSql() as $statement) {
            foreach (explode("\n", $statement) as $line) {
                $line = rtrim($line, ',');
                if (str_starts_with($line, '  CONSTRAINT `chk_')) {
                    $constraints[] = trim($line);
                }
            }
        }

        return $constraints;
    }
}
