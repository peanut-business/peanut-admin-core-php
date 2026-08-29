<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport\Database;

use InvalidArgumentException;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantColumnScope;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;

final class Schema
{
    /** @return list<string> */
    public static function tableNames(): array
    {
        return ['pa_import_export_operation', 'pa_import_export_row_error'];
    }

    public static function createSql(
        string $table,
        TenantPersistenceMode $mode = TenantPersistenceMode::TenantScoped,
    ): string
    {
        $scope = new TenantColumnScope($mode);
        return match ($table) {
            'pa_import_export_operation' => sprintf(<<<'SQL'
CREATE TABLE `pa_import_export_operation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `operation_key` VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
%s  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `provider_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `direction` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `format` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'csv',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'queued',
  `input_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `result_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `error_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `task_job_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `schema_revision` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mapping_json` JSON NOT NULL,
  `processed_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `accepted_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `rejected_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `attempt_number` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `idempotency_key_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `retention_until` DATETIME(3) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_import_export_key` (`operation_key`),
%s  UNIQUE KEY `uk_import_export_idempotency` (%s`created_by_member_id`, `direction`, `provider_key`, `idempotency_key_hash`),
  KEY `idx_import_export_status` (%s`status`, `id`),
  KEY `idx_import_export_task` (%s`task_job_key`),
  KEY `idx_import_export_retention` (`status`, `retention_until`, `id`),
%s  CONSTRAINT `fk_import_export_member` FOREIGN KEY (%s`created_by_member_id`) REFERENCES `pa_tenant_member` (%s`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_import_export_key` CHECK (`operation_key` REGEXP '^iox_[0-9a-f]{32}$'),
  CONSTRAINT `chk_import_export_direction` CHECK (`direction` IN ('import','export')),
  CONSTRAINT `chk_import_export_format` CHECK (`format` = 'csv'),
  CONSTRAINT `chk_import_export_status` CHECK (`status` IN ('queued','running','cancel_requested','succeeded','failed','cancelled','expired')),
  CONSTRAINT `chk_import_export_input` CHECK ((`direction` = 'import') = (`input_file_key` IS NOT NULL)),
  CONSTRAINT `chk_import_export_files` CHECK ((`result_file_key` IS NULL OR `result_file_key` REGEXP '^file_[0-9a-f]{32}$') AND (`error_file_key` IS NULL OR `error_file_key` REGEXP '^file_[0-9a-f]{32}$')),
  CONSTRAINT `chk_import_export_task` CHECK (`task_job_key` IS NULL OR `task_job_key` REGEXP '^job_[0-9a-f]{32}$'),
  CONSTRAINT `chk_import_export_hashes` CHECK (`idempotency_key_hash` REGEXP '^[0-9a-f]{64}$' AND `request_hash` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_import_export_progress` CHECK (`accepted_rows` + `rejected_rows` <= `processed_rows` AND `processed_rows` <= 100000 AND `total_rows` <= 100000),
  CONSTRAINT `chk_import_export_attempt` CHECK (`attempt_number` <= 10),
  CONSTRAINT `chk_import_export_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_import_export_completion` CHECK ((`status` IN ('succeeded','failed','cancelled','expired')) = (`completed_at` IS NOT NULL))
) ENGINE=InnoDB
SQL,
                $scope->whenTenant("  `tenant_id` BIGINT UNSIGNED NOT NULL,\n"),
                $scope->whenTenant("  UNIQUE KEY `uk_import_export_tenant_id` (`tenant_id`, `id`),\n"),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant("  CONSTRAINT `fk_import_export_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,\n"),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant('`tenant_id`, '),
            ),
            'pa_import_export_row_error' => sprintf(<<<'SQL'
CREATE TABLE `pa_import_export_row_error` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
%s  `operation_id` BIGINT UNSIGNED NOT NULL,
  `row_number` INT UNSIGNED NOT NULL,
  `column_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `column_key_unique` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (COALESCE(`column_key`, '')) STORED,
  `error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_import_export_row_error` (%s`operation_id`, `row_number`, `column_key_unique`, `error_code`),
  KEY `idx_import_export_row_error` (%s`operation_id`, `row_number`, `id`),
  CONSTRAINT `fk_import_export_row_operation` FOREIGN KEY (%s`operation_id`) REFERENCES `pa_import_export_operation` (%s`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_import_export_row_number` CHECK (`row_number` >= 2 AND `row_number` <= 100001),
  CONSTRAINT `chk_import_export_column` CHECK (`column_key` IS NULL OR `column_key` REGEXP '^[a-z][a-z0-9_]{0,63}$'),
  CONSTRAINT `chk_import_export_error_code` CHECK (`error_code` REGEXP '^[A-Z][A-Z0-9_]{2,63}$')
) ENGINE=InnoDB
SQL,
                $scope->whenTenant("  `tenant_id` BIGINT UNSIGNED NOT NULL,\n"),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant('`tenant_id`, '),
            ),
            default => throw new InvalidArgumentException('Unknown import-export table.'),
        };
    }

    public static function dropSql(string $table): string
    {
        if (!in_array($table, self::tableNames(), true)) {
            throw new InvalidArgumentException('Unknown import-export table.');
        }
        return sprintf('DROP TABLE IF EXISTS `%s`', $table);
    }
}
