<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Idempotency;

use PeanutAdmin\Kernel\Persistence\Tenancy\TenantColumnScope;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;

final class IdempotencySchema
{
    /** @return list<string> */
    public static function tableNames(): array
    {
        return ['pa_tenant_idempotency_record', 'pa_platform_idempotency_record'];
    }

    public static function tenant(
        TenantPersistenceMode $mode = TenantPersistenceMode::TenantScoped,
    ): string
    {
        $scope = new TenantColumnScope($mode);
        return sprintf(<<<'SQL'
CREATE TABLE `pa_tenant_idempotency_record` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
%s  `tenant_member_id` BIGINT UNSIGNED NOT NULL,
  `operation_key` VARCHAR(160) NOT NULL,
  `idempotency_key_hash` CHAR(64) NOT NULL,
  `request_hash` CHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL,
  `response_status` SMALLINT UNSIGNED NULL,
  `response_body_json` JSON NULL,
  `resource_type` VARCHAR(160) NULL,
  `resource_id` VARCHAR(128) NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_idempotency` (%s`tenant_member_id`, `operation_key`, `idempotency_key_hash`),
  KEY `idx_tenant_idempotency_expiry` (`expires_at`, `status`, `id`),
  CONSTRAINT `fk_tenant_idempotency_member` FOREIGN KEY (%s`tenant_member_id`) REFERENCES `pa_tenant_member` (%s`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_idempotency_status` CHECK (`status` IN ('processing','completed','failed'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            $scope->whenTenant("  `tenant_id` BIGINT UNSIGNED NOT NULL,\n"),
            $scope->whenTenant('`tenant_id`, '),
            $scope->whenTenant('`tenant_id`, '),
            $scope->whenTenant('`tenant_id`, '),
        );
    }

    public static function platform(): string
    {
        return <<<'SQL'
CREATE TABLE `pa_platform_idempotency_record` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_operator_id` BIGINT UNSIGNED NOT NULL,
  `operation_key` VARCHAR(160) NOT NULL,
  `idempotency_key_hash` CHAR(64) NOT NULL,
  `request_hash` CHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL,
  `response_status` SMALLINT UNSIGNED NULL,
  `response_body_json` JSON NULL,
  `resource_type` VARCHAR(160) NULL,
  `resource_id` VARCHAR(128) NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_idempotency` (`platform_operator_id`, `operation_key`, `idempotency_key_hash`),
  KEY `idx_platform_idempotency_expiry` (`expires_at`, `status`, `id`),
  CONSTRAINT `fk_platform_idempotency_operator` FOREIGN KEY (`platform_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_platform_idempotency_status` CHECK (`status` IN ('processing','completed','failed'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;
    }
}
