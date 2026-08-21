<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Database;

use InvalidArgumentException;

final class Schema
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_workflow_definition' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_workflow_definition` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `module_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `workflow_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  `draft_graph_json` JSON NOT NULL,
  `draft_graph_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `latest_version` INT UNSIGNED NOT NULL DEFAULT 0,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `retired_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_definition_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_workflow_definition_identity` (`tenant_id`, `module_key`, `workflow_key`),
  KEY `idx_workflow_definition_status` (`tenant_id`, `status`, `id`),
  CONSTRAINT `fk_workflow_definition_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_definition_module_key` CHECK (`module_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_workflow_definition_workflow_key` CHECK (`workflow_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_workflow_definition_status` CHECK (`status` IN ('draft', 'active', 'retired')),
  CONSTRAINT `chk_workflow_definition_digest` CHECK (`draft_graph_sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_workflow_definition_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_workflow_definition_retired_shape` CHECK ((`status` = 'retired' AND `retired_at` IS NOT NULL) OR (`status` <> 'retired' AND `retired_at` IS NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_workflow_definition_version' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_workflow_definition_version` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `definition_id` BIGINT UNSIGNED NOT NULL,
  `version` INT UNSIGNED NOT NULL,
  `graph_json` JSON NOT NULL,
  `graph_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `published_by_member_id` BIGINT UNSIGNED NOT NULL,
  `published_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_definition_version_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_workflow_definition_version_number` (`tenant_id`, `definition_id`, `version`),
  UNIQUE KEY `uk_workflow_definition_version_digest` (`tenant_id`, `definition_id`, `graph_sha256`),
  KEY `idx_workflow_definition_version_published` (`tenant_id`, `published_at`, `id`),
  CONSTRAINT `fk_workflow_definition_version_definition` FOREIGN KEY (`tenant_id`, `definition_id`) REFERENCES `pa_workflow_definition` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_definition_version_number` CHECK (`version` >= 1),
  CONSTRAINT `chk_workflow_definition_version_digest` CHECK (`graph_sha256` REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_workflow_instance' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_workflow_instance` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instance_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `definition_id` BIGINT UNSIGNED NOT NULL,
  `definition_version` INT UNSIGNED NOT NULL,
  `subject_type` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_revision_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_revision_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `current_node_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `initiated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `last_actor_member_id` BIGINT UNSIGNED NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  `cancelled_at` DATETIME(3) NULL,
  `active_marker` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = 'active' THEN 1 ELSE NULL END) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_instance_key` (`instance_key`),
  UNIQUE KEY `uk_workflow_instance_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_workflow_instance_active_subject` (`tenant_id`, `definition_id`, `subject_type`, `subject_key`, `active_marker`),
  KEY `idx_workflow_instance_status` (`tenant_id`, `status`, `updated_at`, `id`),
  KEY `idx_workflow_instance_subject` (`tenant_id`, `subject_type`, `subject_key`, `id`),
  CONSTRAINT `fk_workflow_instance_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_workflow_instance_version` FOREIGN KEY (`tenant_id`, `definition_id`, `definition_version`) REFERENCES `pa_workflow_definition_version` (`tenant_id`, `definition_id`, `version`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_instance_key` CHECK (`instance_key` REGEXP '^instance_[0-9a-f]{32}$'),
  CONSTRAINT `chk_workflow_instance_version` CHECK (`definition_version` >= 1),
  CONSTRAINT `chk_workflow_instance_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_workflow_instance_digest` CHECK (`subject_revision_sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_workflow_instance_status` CHECK (`status` IN ('active', 'completed', 'cancelled')),
  CONSTRAINT `chk_workflow_instance_terminal_shape` CHECK ((`status` = 'active' AND `completed_at` IS NULL AND `cancelled_at` IS NULL) OR (`status` = 'completed' AND `completed_at` IS NOT NULL AND `cancelled_at` IS NULL) OR (`status` = 'cancelled' AND `completed_at` IS NULL AND `cancelled_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_workflow_work_item' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_workflow_work_item` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `work_item_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `instance_id` BIGINT UNSIGNED NOT NULL,
  `node_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `round_no` INT UNSIGNED NOT NULL,
  `assignment_source_kind` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `assignment_source_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `assignee_member_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `decision` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `completed_by_member_id` BIGINT UNSIGNED NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  `cancelled_at` DATETIME(3) NULL,
  `pending_marker` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = 'pending' THEN 1 ELSE NULL END) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_work_item_key` (`work_item_key`),
  UNIQUE KEY `uk_workflow_work_item_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_workflow_work_item_pending_assignee` (`tenant_id`, `instance_id`, `node_key`, `round_no`, `assignee_member_id`, `pending_marker`),
  KEY `idx_workflow_work_item_assignee` (`tenant_id`, `assignee_member_id`, `status`, `created_at`, `id`),
  KEY `idx_workflow_work_item_instance` (`tenant_id`, `instance_id`, `status`, `id`),
  CONSTRAINT `fk_workflow_work_item_instance` FOREIGN KEY (`tenant_id`, `instance_id`) REFERENCES `pa_workflow_instance` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_work_item_key` CHECK (`work_item_key` REGEXP '^work_[0-9a-f]{32}$'),
  CONSTRAINT `chk_workflow_work_item_round` CHECK (`round_no` >= 1),
  CONSTRAINT `chk_workflow_work_item_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_workflow_work_item_source` CHECK (`assignment_source_kind` IN ('member', 'role', 'department', 'initiator', 'previous_actor')),
  CONSTRAINT `chk_workflow_work_item_status` CHECK (`status` IN ('pending', 'completed', 'cancelled')),
  CONSTRAINT `chk_workflow_work_item_terminal_shape` CHECK ((`status` = 'pending' AND `decision` IS NULL AND `completed_by_member_id` IS NULL AND `completed_at` IS NULL AND `cancelled_at` IS NULL) OR (`status` = 'completed' AND `decision` IS NOT NULL AND `completed_by_member_id` IS NOT NULL AND `completed_at` IS NOT NULL AND `cancelled_at` IS NULL) OR (`status` = 'cancelled' AND `decision` IS NULL AND `completed_by_member_id` IS NULL AND `completed_at` IS NULL AND `cancelled_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_workflow_event' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_workflow_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `instance_id` BIGINT UNSIGNED NOT NULL,
  `sequence_no` INT UNSIGNED NOT NULL,
  `event_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `transition_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `from_node_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `to_node_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_member_id` BIGINT UNSIGNED NULL,
  `subject_revision_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_revision_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `comment_text` VARCHAR(2000) NULL,
  `comment_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `attachment_snapshots_json` JSON NOT NULL,
  `metadata_json` JSON NOT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_event_sequence` (`tenant_id`, `instance_id`, `sequence_no`),
  KEY `idx_workflow_event_instance_time` (`tenant_id`, `instance_id`, `occurred_at`, `id`),
  KEY `idx_workflow_event_key_time` (`tenant_id`, `event_key`, `occurred_at`, `id`),
  CONSTRAINT `fk_workflow_event_instance` FOREIGN KEY (`tenant_id`, `instance_id`) REFERENCES `pa_workflow_instance` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_event_sequence` CHECK (`sequence_no` >= 1),
  CONSTRAINT `chk_workflow_event_key` CHECK (`event_key` REGEXP '^tenant\\.workflow\\.[a-z_]+$'),
  CONSTRAINT `chk_workflow_event_subject_digest` CHECK (`subject_revision_sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_workflow_event_comment_shape` CHECK ((`comment_text` IS NULL AND `comment_sha256` IS NULL) OR (`comment_text` IS NOT NULL AND `comment_sha256` REGEXP '^[0-9a-f]{64}$')),
  CONSTRAINT `chk_workflow_event_actor_shape` CHECK ((`actor_type` = 'member' AND `actor_member_id` IS NOT NULL) OR (`actor_type` = 'tenant_system' AND `actor_member_id` IS NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    ];

    private function __construct() {}

    /** @return list<string> */
    public static function tableNames(): array
    {
        return array_keys(self::CREATE_SQL);
    }

    /** @return list<string> */
    public static function createSql(): array
    {
        return array_values(self::CREATE_SQL);
    }

    public static function createTableSql(string $table): string
    {
        return self::CREATE_SQL[$table]
            ?? throw new InvalidArgumentException("Unknown workflow table: {$table}");
    }

    public static function dropSql(string $table): string
    {
        if (!isset(self::CREATE_SQL[$table])) {
            throw new InvalidArgumentException("Unknown workflow table: {$table}");
        }

        return "DROP TABLE IF EXISTS `{$table}`";
    }
}
