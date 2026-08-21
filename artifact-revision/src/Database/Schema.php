<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Database;

use InvalidArgumentException;

final class Schema
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_artifact' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_artifact` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `artifact_type` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `artifact_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `next_revision_number` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `latest_finalized_revision_id` BIGINT UNSIGNED NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_artifact_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_artifact_identity` (`tenant_id`, `artifact_type`, `artifact_key`),
  KEY `idx_artifact_tenant_type` (`tenant_id`, `artifact_type`, `id`),
  KEY `idx_artifact_latest_finalized` (`tenant_id`, `latest_finalized_revision_id`),
  CONSTRAINT `fk_artifact_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_artifact_created_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_artifact_updated_member` FOREIGN KEY (`tenant_id`, `updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_artifact_type` CHECK (`artifact_type` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_artifact_key` CHECK (`artifact_key` <> ''),
  CONSTRAINT `chk_artifact_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_artifact_next_revision_number` CHECK (`next_revision_number` >= 1)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_artifact_revision' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_artifact_revision` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `artifact_id` BIGINT UNSIGNED NOT NULL,
  `revision_key` VARCHAR(41) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision_number` BIGINT UNSIGNED NOT NULL,
  `parent_revision_id` BIGINT UNSIGNED NULL,
  `state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `payload_schema_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `payload_schema_version` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `payload_ref` VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `payload_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `attachment_manifest_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `canonical_envelope_json` JSON NULL,
  `canonical_envelope_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `finalized_by_member_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME(3) NOT NULL,
  `finalized_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_artifact_revision_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_artifact_revision_key` (`tenant_id`, `revision_key`),
  UNIQUE KEY `uk_artifact_revision_artifact_id` (`tenant_id`, `artifact_id`, `id`),
  UNIQUE KEY `uk_artifact_revision_number` (`tenant_id`, `artifact_id`, `revision_number`),
  KEY `idx_artifact_revision_artifact_state` (`tenant_id`, `artifact_id`, `state`, `revision_number`),
  KEY `idx_artifact_revision_parent` (`tenant_id`, `artifact_id`, `parent_revision_id`),
  CONSTRAINT `fk_artifact_revision_artifact` FOREIGN KEY (`tenant_id`, `artifact_id`) REFERENCES `pa_artifact` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_artifact_revision_parent` FOREIGN KEY (`tenant_id`, `artifact_id`, `parent_revision_id`) REFERENCES `pa_artifact_revision` (`tenant_id`, `artifact_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_artifact_revision_created_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_artifact_revision_finalized_member` FOREIGN KEY (`tenant_id`, `finalized_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_artifact_revision_key` CHECK (`revision_key` REGEXP '^revision_[0-9a-f]{32}$'),
  CONSTRAINT `chk_artifact_revision_number` CHECK (`revision_number` >= 1),
  CONSTRAINT `chk_artifact_revision_parent` CHECK (`parent_revision_id` IS NULL OR `parent_revision_id` <> `id`),
  CONSTRAINT `chk_artifact_revision_state` CHECK (`state` IN ('pending', 'finalized')),
  CONSTRAINT `chk_artifact_revision_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_artifact_revision_schema_key` CHECK (`payload_schema_key` IS NULL OR `payload_schema_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_artifact_revision_schema_version` CHECK (`payload_schema_version` IS NULL OR `payload_schema_version` <> ''),
  CONSTRAINT `chk_artifact_revision_payload_ref` CHECK (`payload_ref` IS NULL OR `payload_ref` <> ''),
  CONSTRAINT `chk_artifact_revision_payload_digest` CHECK (`payload_sha256` IS NULL OR `payload_sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_artifact_revision_attachment_digest` CHECK (`attachment_manifest_sha256` IS NULL OR `attachment_manifest_sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_artifact_revision_envelope_digest` CHECK (`canonical_envelope_sha256` IS NULL OR `canonical_envelope_sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_artifact_revision_pending_shape` CHECK (
    (`state` = 'pending' AND `payload_schema_key` IS NULL AND `payload_schema_version` IS NULL AND `payload_ref` IS NULL AND `payload_sha256` IS NULL AND `attachment_manifest_sha256` IS NULL AND `canonical_envelope_json` IS NULL AND `canonical_envelope_sha256` IS NULL AND `finalized_by_member_id` IS NULL AND `finalized_at` IS NULL)
    OR (`state` = 'finalized' AND `payload_schema_key` IS NOT NULL AND `payload_schema_version` IS NOT NULL AND `payload_ref` IS NOT NULL AND `payload_sha256` IS NOT NULL AND `canonical_envelope_json` IS NOT NULL AND `canonical_envelope_sha256` IS NOT NULL AND `finalized_by_member_id` IS NOT NULL AND `finalized_at` IS NOT NULL)
  )
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
            ?? throw new InvalidArgumentException("Unknown artifact-revision table: {$table}");
    }

    public static function dropSql(string $table): string
    {
        if (!isset(self::CREATE_SQL[$table])) {
            throw new InvalidArgumentException("Unknown artifact-revision table: {$table}");
        }

        return "DROP TABLE IF EXISTS `{$table}`";
    }
}
