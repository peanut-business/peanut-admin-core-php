<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Database;

use InvalidArgumentException;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantColumnScope;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;

final class Schema
{
    /** @return list<string> */
    public static function tableNames(): array
    {
        return ['pa_task_job', 'pa_task_job_attempt', 'pa_task_job_event'];
    }

    public static function createSql(
        string $table,
        TenantPersistenceMode $mode = TenantPersistenceMode::TenantScoped,
    ): string
    {
        $scope = new TenantColumnScope($mode);
        return match ($table) {
            'pa_task_job' => sprintf(<<<'SQL'
CREATE TABLE `pa_task_job` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
%s  `task_type` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `handler_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_json` JSON NOT NULL,
  `payload_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `trusted_envelope` TEXT NOT NULL,
  `idempotency_key_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `request_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'queued',
  `priority` SMALLINT NOT NULL DEFAULT 0,
  `attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` SMALLINT UNSIGNED NOT NULL,
  `available_at` DATETIME(3) NOT NULL,
  `lease_owner_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `lease_token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `lease_expires_at` DATETIME(3) NULL,
  `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_job_key` (`job_key`),
%s  UNIQUE KEY `uk_task_job_idempotency` (%s`created_by_member_id`, `task_type`, `idempotency_key_hash`),
  KEY `idx_task_job_claim` (%s`status`, `available_at`, `priority`, `id`),
  KEY `idx_task_job_lease` (`status`, `lease_expires_at`, `id`),
%s  CONSTRAINT `fk_task_job_member` FOREIGN KEY (%s`created_by_member_id`) REFERENCES `pa_tenant_member` (%s`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_task_job_key` CHECK (`job_key` REGEXP '^job_[0-9a-f]{32}$'),
  CONSTRAINT `chk_task_job_payload_hash` CHECK (`payload_hash` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_task_job_status` CHECK (`status` IN ('queued','running','succeeded','dead','cancelled')),
  CONSTRAINT `chk_task_job_attempts` CHECK (`max_attempts` BETWEEN 1 AND 10 AND `attempt_count` <= `max_attempts`),
  CONSTRAINT `chk_task_job_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_task_job_lease` CHECK ((`status` = 'running') = (`lease_owner_hash` IS NOT NULL AND `lease_token_hash` IS NOT NULL AND `lease_expires_at` IS NOT NULL)),
  CONSTRAINT `chk_task_job_completion` CHECK ((`status` IN ('succeeded','dead','cancelled')) = (`completed_at` IS NOT NULL))
) ENGINE=InnoDB
SQL,
                $scope->whenTenant("  `tenant_id` BIGINT UNSIGNED NOT NULL,\n"),
                $scope->whenTenant("  UNIQUE KEY `uk_task_job_tenant_id` (`tenant_id`, `id`),\n"),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant("  CONSTRAINT `fk_task_job_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,\n"),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant('`tenant_id`, '),
            ),
            'pa_task_job_attempt' => sprintf(<<<'SQL'
CREATE TABLE `pa_task_job_attempt` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
%s  `job_id` BIGINT UNSIGNED NOT NULL,
  `attempt_number` SMALLINT UNSIGNED NOT NULL,
  `worker_id_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lease_token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'running',
  `error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `started_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_attempt_number` (%s`job_id`, `attempt_number`),
  KEY `idx_task_attempt_status` (%s`status`, `started_at`, `id`),
  CONSTRAINT `fk_task_attempt_job` FOREIGN KEY (%s`job_id`) REFERENCES `pa_task_job` (%s`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_task_attempt_status` CHECK (`status` IN ('running','succeeded','retry','dead','abandoned')),
  CONSTRAINT `chk_task_attempt_completion` CHECK ((`status` = 'running') = (`completed_at` IS NULL))
) ENGINE=InnoDB
SQL,
                $scope->whenTenant("  `tenant_id` BIGINT UNSIGNED NOT NULL,\n"),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant('`tenant_id`, '),
            ),
            'pa_task_job_event' => sprintf(<<<'SQL'
CREATE TABLE `pa_task_job_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
%s  `job_id` BIGINT UNSIGNED NOT NULL,
  `event_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_member_id` BIGINT UNSIGNED NULL,
  `metadata_json` JSON NOT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_task_event_job` (%s`job_id`, `id`),
  KEY `idx_task_event_time` (%s`occurred_at`, `id`),
  CONSTRAINT `fk_task_event_job` FOREIGN KEY (%s`job_id`) REFERENCES `pa_task_job` (%s`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_task_event_member` FOREIGN KEY (%s`actor_member_id`) REFERENCES `pa_tenant_member` (%s`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_task_event_key` CHECK (`event_key` REGEXP '^tenant\\.task\\.[a-z_]+$')
) ENGINE=InnoDB
SQL,
                $scope->whenTenant("  `tenant_id` BIGINT UNSIGNED NOT NULL,\n"),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant('`tenant_id`, '),
                $scope->whenTenant('`tenant_id`, '),
            ),
            default => throw new InvalidArgumentException('Unknown task-job table.'),
        };
    }

    public static function dropSql(string $table): string
    {
        if (!in_array($table, self::tableNames(), true)) {
            throw new InvalidArgumentException('Unknown task-job table.');
        }

        return sprintf('DROP TABLE IF EXISTS `%s`', $table);
    }
}
