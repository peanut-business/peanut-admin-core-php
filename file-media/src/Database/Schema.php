<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Database;

use InvalidArgumentException;

final class Schema
{
    private const CREATE_SQL = <<<'SQL'
CREATE TABLE `pa_file_object` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `storage_provider_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `storage_key` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `media_type` VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL,
  `sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ready',
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `archived_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_file_object_key` (`file_key`),
  UNIQUE KEY `uk_file_object_storage` (`tenant_id`, `storage_provider_key`, `storage_key`),
  KEY `idx_file_object_status` (`tenant_id`, `status`, `id`),
  KEY `idx_file_object_sha256` (`tenant_id`, `sha256`),
  CONSTRAINT `fk_file_object_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_file_object_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_file_object_key` CHECK (`file_key` REGEXP '^file_[0-9a-f]{32}$'),
  CONSTRAINT `chk_file_object_sha256` CHECK (`sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_file_object_status` CHECK (`status` IN ('ready', 'archived')),
  CONSTRAINT `chk_file_object_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_file_object_archive_shape` CHECK (
    (`status` = 'ready' AND `archived_at` IS NULL)
    OR (`status` = 'archived' AND `archived_at` IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

    private function __construct() {}

    /** @return list<string> */
    public static function tableNames(): array
    {
        return ['pa_file_object'];
    }

    public static function createSql(string $table): string
    {
        if ($table !== 'pa_file_object') {
            throw new InvalidArgumentException("Unknown file-media table: {$table}");
        }

        return self::CREATE_SQL;
    }

    public static function dropSql(string $table): string
    {
        if ($table !== 'pa_file_object') {
            throw new InvalidArgumentException("Unknown file-media table: {$table}");
        }

        return 'DROP TABLE IF EXISTS `pa_file_object`';
    }
}
