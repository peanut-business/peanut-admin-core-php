<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Database;

use InvalidArgumentException;

final class Schema
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_reference_code_set' => <<<'SQL'
CREATE TABLE `pa_reference_code_set` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `set_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `definition_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lifecycle` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reference_code_set` (`module_key`, `set_key`),
  KEY `idx_reference_code_set_lifecycle` (`lifecycle`, `module_key`, `set_key`),
  CONSTRAINT `chk_reference_code_set_lifecycle` CHECK (`lifecycle` IN ('active', 'retired')),
  CONSTRAINT `chk_reference_code_set_revision` CHECK (`revision` >= 1)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_reference_code_entry' => <<<'SQL'
CREATE TABLE `pa_reference_code_entry` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `set_id` BIGINT UNSIGNED NOT NULL,
  `code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lifecycle` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `retired_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reference_code_entry` (`tenant_id`, `set_id`, `code`),
  KEY `idx_reference_code_entry_lookup` (`tenant_id`, `set_id`, `lifecycle`, `code`),
  CONSTRAINT `fk_reference_code_entry_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_reference_code_entry_set` FOREIGN KEY (`set_id`) REFERENCES `pa_reference_code_set` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_reference_code_entry_created_member` FOREIGN KEY (`created_by_member_id`) REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_reference_code_entry_updated_member` FOREIGN KEY (`updated_by_member_id`) REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_reference_code_entry_lifecycle` CHECK (`lifecycle` IN ('active', 'retired')),
  CONSTRAINT `chk_reference_code_entry_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_reference_code_entry_retired_shape` CHECK (
    (`lifecycle` = 'active' AND `retired_at` IS NULL)
    OR (`lifecycle` = 'retired' AND `retired_at` IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_reference_code_entry_version' => <<<'SQL'
CREATE TABLE `pa_reference_code_entry_version` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entry_id` BIGINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL,
  `label` VARCHAR(160) NOT NULL,
  `metadata_json` JSON NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `effective_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NULL,
  `changed_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reference_code_entry_version` (`entry_id`, `revision`),
  KEY `idx_reference_code_entry_version_effective` (`entry_id`, `effective_at`, `expires_at`, `revision`),
  KEY `idx_reference_code_entry_version_status` (`entry_id`, `status`, `effective_at`, `expires_at`, `revision`),
  CONSTRAINT `fk_reference_code_entry_version_entry` FOREIGN KEY (`entry_id`) REFERENCES `pa_reference_code_entry` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_reference_code_entry_version_member` FOREIGN KEY (`changed_by_member_id`) REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_reference_code_entry_version_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_reference_code_entry_version_status` CHECK (`status` IN ('active', 'inactive')),
  CONSTRAINT `chk_reference_code_entry_version_sort` CHECK (`sort_order` BETWEEN -1000000 AND 1000000),
  CONSTRAINT `chk_reference_code_entry_version_interval` CHECK (`expires_at` IS NULL OR `expires_at` > `effective_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    ];

    private function __construct() {}

    /** @return list<string> */
    public static function tableNames(): array
    {
        return array_keys(self::CREATE_SQL);
    }

    public static function createSql(string $table): string
    {
        return self::CREATE_SQL[$table]
            ?? throw new InvalidArgumentException("Unknown reference-code table: {$table}");
    }

    public static function dropSql(string $table): string
    {
        if (!isset(self::CREATE_SQL[$table])) {
            throw new InvalidArgumentException("Unknown reference-code table: {$table}");
        }

        return "DROP TABLE IF EXISTS `{$table}`";
    }
}
