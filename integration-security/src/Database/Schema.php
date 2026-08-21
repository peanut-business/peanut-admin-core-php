<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Database;

use InvalidArgumentException;

final class Schema
{
    /** @return list<string> */
    public static function tableNames(): array
    {
        return [
            'pa_integration_machine_identity',
            'pa_integration_webhook_endpoint',
            'pa_integration_webhook_delivery',
            'pa_integration_webhook_attempt',
            'pa_integration_security_event',
        ];
    }

    public static function createSql(string $table): string
    {
        return match ($table) {
            'pa_integration_machine_identity' => <<<'SQL'
CREATE TABLE `pa_integration_machine_identity` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `identity_key` CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `family_key` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `scopes_json` JSON NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `token_prefix` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `token_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `token_last_four` CHAR(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` DATETIME(3) NULL,
  `last_used_at` DATETIME(3) NULL,
  `rotated_at` DATETIME(3) NULL,
  `revoked_at` DATETIME(3) NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_integration_machine_key` (`tenant_id`, `identity_key`),
  UNIQUE KEY `uk_integration_machine_digest` (`token_digest`),
  KEY `idx_integration_machine_status` (`tenant_id`, `status`, `expires_at`, `id`),
  KEY `idx_integration_machine_family` (`tenant_id`, `family_key`, `id`),
  CONSTRAINT `fk_integration_machine_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_integration_machine_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_integration_machine_status` CHECK (`status` IN ('active', 'rotated', 'revoked')),
  CONSTRAINT `chk_integration_machine_terminal` CHECK ((`status` = 'active' AND `rotated_at` IS NULL AND `revoked_at` IS NULL) OR (`status` = 'rotated' AND `rotated_at` IS NOT NULL AND `revoked_at` IS NULL) OR (`status` = 'revoked' AND `revoked_at` IS NOT NULL AND `rotated_at` IS NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            'pa_integration_webhook_endpoint' => <<<'SQL'
CREATE TABLE `pa_integration_webhook_endpoint` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `endpoint_key` CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `url` VARCHAR(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `events_json` JSON NOT NULL,
  `secret_ciphertext` TEXT NOT NULL,
  `secret_key_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `disabled_at` DATETIME(3) NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_integration_webhook_key` (`tenant_id`, `endpoint_key`),
  UNIQUE KEY `uk_integration_webhook_id` (`tenant_id`, `id`),
  KEY `idx_integration_webhook_status` (`tenant_id`, `status`, `id`),
  CONSTRAINT `fk_integration_webhook_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_integration_webhook_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_integration_webhook_status` CHECK (`status` IN ('active', 'disabled')),
  CONSTRAINT `chk_integration_webhook_disabled` CHECK ((`status` = 'active' AND `disabled_at` IS NULL) OR (`status` = 'disabled' AND `disabled_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            'pa_integration_webhook_delivery' => <<<'SQL'
CREATE TABLE `pa_integration_webhook_delivery` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `endpoint_id` BIGINT UNSIGNED NOT NULL,
  `delivery_key` CHAR(41) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_type` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_json` JSON NULL,
  `payload_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'pending',
  `attempt_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `available_at` DATETIME(3) NOT NULL,
  `lease_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `lease_expires_at` DATETIME(3) NULL,
  `last_status_code` SMALLINT UNSIGNED NULL,
  `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `delivered_at` DATETIME(3) NULL,
  `payload_expires_at` DATETIME(3) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_integration_delivery_key` (`tenant_id`, `delivery_key`),
  UNIQUE KEY `uk_integration_delivery_event` (`tenant_id`, `endpoint_id`, `event_key`),
  UNIQUE KEY `uk_integration_delivery_id` (`tenant_id`, `id`),
  KEY `idx_integration_delivery_claim` (`tenant_id`, `status`, `available_at`, `id`),
  KEY `idx_integration_delivery_retention` (`status`, `updated_at`, `id`),
  CONSTRAINT `fk_integration_delivery_endpoint` FOREIGN KEY (`tenant_id`, `endpoint_id`) REFERENCES `pa_integration_webhook_endpoint` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_integration_delivery_status` CHECK (`status` IN ('pending', 'delivering', 'retryable', 'delivered', 'permanent_failed')),
  CONSTRAINT `chk_integration_delivery_attempts` CHECK (`attempt_count` <= 8),
  CONSTRAINT `chk_integration_delivery_lease` CHECK ((`status` = 'delivering' AND `lease_digest` IS NOT NULL AND `lease_expires_at` IS NOT NULL) OR (`status` <> 'delivering' AND `lease_digest` IS NULL AND `lease_expires_at` IS NULL)),
  CONSTRAINT `chk_integration_delivery_result` CHECK ((`status` = 'delivered' AND `delivered_at` IS NOT NULL) OR (`status` <> 'delivered' AND `delivered_at` IS NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            'pa_integration_webhook_attempt' => <<<'SQL'
CREATE TABLE `pa_integration_webhook_attempt` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `delivery_id` BIGINT UNSIGNED NOT NULL,
  `attempt_number` TINYINT UNSIGNED NOT NULL,
  `outcome` VARCHAR(24) NOT NULL,
  `response_status` SMALLINT UNSIGNED NULL,
  `error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `duration_ms` INT UNSIGNED NOT NULL,
  `attempted_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_integration_attempt_number` (`tenant_id`, `delivery_id`, `attempt_number`),
  KEY `idx_integration_attempt_time` (`tenant_id`, `attempted_at`, `id`),
  CONSTRAINT `fk_integration_attempt_delivery` FOREIGN KEY (`tenant_id`, `delivery_id`) REFERENCES `pa_integration_webhook_delivery` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_integration_attempt_outcome` CHECK (`outcome` IN ('delivered', 'retryable', 'permanent_failed'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            'pa_integration_security_event' => <<<'SQL'
CREATE TABLE `pa_integration_security_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `event_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_member_id` BIGINT UNSIGNED NULL,
  `target_type` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_key_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `metadata_json` JSON NOT NULL,
  `request_id_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_integration_event_time` (`tenant_id`, `occurred_at`, `id`),
  KEY `idx_integration_event_target` (`tenant_id`, `target_type`, `target_key_hash`, `id`),
  CONSTRAINT `fk_integration_event_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_integration_event_member` FOREIGN KEY (`tenant_id`, `actor_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_integration_event_key` CHECK (`event_key` REGEXP '^tenant\\.integration\\.[a-z_]+$')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            default => throw new InvalidArgumentException('Unknown integration security table.'),
        };
    }
}
