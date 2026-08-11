<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Database;

use InvalidArgumentException;

final class Schema
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_entitlement_grant' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_entitlement_grant` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `grant_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `current_policy_revision_id` BIGINT UNSIGNED NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement_grant_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_entitlement_grant_key` (`tenant_id`, `grant_key`),
  KEY `idx_entitlement_grant_current_policy` (`tenant_id`, `current_policy_revision_id`),
  CONSTRAINT `fk_entitlement_grant_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_grant_created_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_grant_updated_member` FOREIGN KEY (`tenant_id`, `updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_entitlement_grant_key` CHECK (`grant_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_entitlement_grant_state` CHECK (`state` IN ('active', 'suspended')),
  CONSTRAINT `chk_entitlement_grant_revision` CHECK (`revision` >= 1)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_entitlement_policy_revision' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_entitlement_policy_revision` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `grant_id` BIGINT UNSIGNED NOT NULL,
  `policy_revision_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `meter_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `unit_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `limit_amount` BIGINT NOT NULL,
  `period_kind` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `effective_from` DATETIME(3) NOT NULL,
  `effective_until` DATETIME(3) NOT NULL,
  `reservation_ttl_seconds` INT UNSIGNED NOT NULL,
  `canonical_snapshot_json` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `canonical_snapshot_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement_policy_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_entitlement_policy_revision_key` (`tenant_id`, `policy_revision_key`),
  UNIQUE KEY `uk_entitlement_policy_grant_revision` (`tenant_id`, `grant_id`, `policy_revision_key`),
  KEY `idx_entitlement_policy_grant` (`tenant_id`, `grant_id`, `created_at`),
  CONSTRAINT `fk_entitlement_policy_grant` FOREIGN KEY (`tenant_id`, `grant_id`) REFERENCES `pa_entitlement_grant` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_policy_created_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_entitlement_policy_revision_key` CHECK (`policy_revision_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_entitlement_policy_meter_key` CHECK (`meter_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_entitlement_policy_unit_key` CHECK (`unit_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_entitlement_policy_limit` CHECK (`limit_amount` >= 1),
  CONSTRAINT `chk_entitlement_policy_period` CHECK (`period_kind` IN ('lifetime', 'utc_day', 'utc_month')),
  CONSTRAINT `chk_entitlement_policy_effective_interval` CHECK (`effective_until` > `effective_from`),
  CONSTRAINT `chk_entitlement_policy_ttl` CHECK (`reservation_ttl_seconds` BETWEEN 30 AND 86400),
  CONSTRAINT `chk_entitlement_policy_snapshot_json` CHECK (JSON_VALID(`canonical_snapshot_json`)),
  CONSTRAINT `chk_entitlement_policy_digest` CHECK (`canonical_snapshot_sha256` REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_entitlement_usage_window' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_entitlement_usage_window` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `policy_revision_id` BIGINT UNSIGNED NOT NULL,
  `meter_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_type` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `window_start` DATETIME(3) NOT NULL,
  `window_end` DATETIME(3) NOT NULL,
  `committed_amount` BIGINT NOT NULL DEFAULT 0,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement_window_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_entitlement_window_identity` (`tenant_id`, `policy_revision_id`, `meter_key`, `target_type`, `target_key`, `window_start`),
  KEY `idx_entitlement_window_target` (`tenant_id`, `meter_key`, `target_type`, `target_key`, `window_start`),
  CONSTRAINT `fk_entitlement_window_policy` FOREIGN KEY (`tenant_id`, `policy_revision_id`) REFERENCES `pa_entitlement_policy_revision` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_entitlement_window_meter_key` CHECK (`meter_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_entitlement_window_target_type` CHECK (`target_type` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_entitlement_window_target_key` CHECK (`target_key` REGEXP '^[ -~]+$'),
  CONSTRAINT `chk_entitlement_window_interval` CHECK (`window_end` > `window_start`),
  CONSTRAINT `chk_entitlement_window_committed` CHECK (`committed_amount` >= 0),
  CONSTRAINT `chk_entitlement_window_revision` CHECK (`revision` >= 1)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_entitlement_reservation' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_entitlement_reservation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `usage_window_id` BIGINT UNSIGNED NOT NULL,
  `reservation_key` VARCHAR(44) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `meter_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_type` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `amount` BIGINT NOT NULL,
  `state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `settled_by_member_id` BIGINT UNSIGNED NULL,
  `reserved_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `settled_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement_reservation_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_entitlement_reservation_key` (`tenant_id`, `reservation_key`),
  UNIQUE KEY `uk_entitlement_reservation_window_id` (`tenant_id`, `usage_window_id`, `id`),
  KEY `idx_entitlement_reservation_window_state` (`tenant_id`, `usage_window_id`, `state`, `expires_at`),
  CONSTRAINT `fk_entitlement_reservation_window` FOREIGN KEY (`tenant_id`, `usage_window_id`) REFERENCES `pa_entitlement_usage_window` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_reservation_created_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_reservation_settled_member` FOREIGN KEY (`tenant_id`, `settled_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_entitlement_reservation_key` CHECK (`reservation_key` REGEXP '^reservation_[0-9a-f]{32}$'),
  CONSTRAINT `chk_entitlement_reservation_meter_key` CHECK (`meter_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_entitlement_reservation_target_type` CHECK (`target_type` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_entitlement_reservation_target_key` CHECK (`target_key` REGEXP '^[ -~]+$'),
  CONSTRAINT `chk_entitlement_reservation_amount` CHECK (`amount` >= 1),
  CONSTRAINT `chk_entitlement_reservation_state` CHECK (`state` IN ('pending', 'committed', 'released', 'expired')),
  CONSTRAINT `chk_entitlement_reservation_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_entitlement_reservation_expiry` CHECK (`expires_at` > `reserved_at`),
  CONSTRAINT `chk_entitlement_reservation_settlement` CHECK (
    (`state` = 'pending' AND `settled_by_member_id` IS NULL AND `settled_at` IS NULL)
    OR (`state` IN ('committed', 'released', 'expired') AND `settled_by_member_id` IS NOT NULL AND `settled_at` IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_entitlement_usage_ledger' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_entitlement_usage_ledger` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `usage_window_id` BIGINT UNSIGNED NOT NULL,
  `reservation_id` BIGINT UNSIGNED NOT NULL,
  `event_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `amount` BIGINT NOT NULL,
  `actor_member_id` BIGINT UNSIGNED NOT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement_ledger_event_key` (`tenant_id`, `event_key`),
  UNIQUE KEY `uk_entitlement_ledger_reservation_event` (`tenant_id`, `reservation_id`, `event_type`),
  KEY `idx_entitlement_ledger_window` (`tenant_id`, `usage_window_id`, `id`),
  CONSTRAINT `fk_entitlement_ledger_window` FOREIGN KEY (`tenant_id`, `usage_window_id`) REFERENCES `pa_entitlement_usage_window` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_ledger_reservation` FOREIGN KEY (`tenant_id`, `usage_window_id`, `reservation_id`) REFERENCES `pa_entitlement_reservation` (`tenant_id`, `usage_window_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_ledger_actor_member` FOREIGN KEY (`tenant_id`, `actor_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_entitlement_ledger_event_key` CHECK (`event_key` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_entitlement_ledger_event_type` CHECK (`event_type` IN ('reserved', 'committed', 'released', 'expired')),
  CONSTRAINT `chk_entitlement_ledger_amount` CHECK (`amount` >= 1)
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
            ?? throw new InvalidArgumentException("Unknown entitlement-quota table: {$table}");
    }

    public static function dropSql(string $table): string
    {
        if (!isset(self::CREATE_SQL[$table])) {
            throw new InvalidArgumentException("Unknown entitlement-quota table: {$table}");
        }

        return "DROP TABLE IF EXISTS `{$table}`";
    }
}
