<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Database;

use InvalidArgumentException;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantColumnScope;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;

final class Schema
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_setting_definition' => <<<'SQL'
CREATE TABLE `pa_setting_definition` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `setting_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `schema_json` JSON NOT NULL,
  `required_flag` TINYINT UNSIGNED NOT NULL,
  `secret_flag` TINYINT UNSIGNED NOT NULL,
  `deployment_scope_flag` TINYINT UNSIGNED NOT NULL,
  `tenant_scope_flag` TINYINT UNSIGNED NOT NULL,
  `target_scope_flag` TINYINT UNSIGNED NOT NULL,
  `target_resource_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `target_operation` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `default_json` JSON NULL,
  `definition_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_definition` (`module_key`, `setting_key`),
  CONSTRAINT `chk_setting_definition_required` CHECK (`required_flag` IN (0, 1)),
  CONSTRAINT `chk_setting_definition_secret` CHECK (`secret_flag` IN (0, 1)),
  CONSTRAINT `chk_setting_definition_deployment_scope` CHECK (`deployment_scope_flag` IN (0, 1)),
  CONSTRAINT `chk_setting_definition_tenant_scope` CHECK (`tenant_scope_flag` IN (0, 1)),
  CONSTRAINT `chk_setting_definition_target_scope` CHECK (`target_scope_flag` IN (0, 1)),
  CONSTRAINT `chk_setting_definition_target` CHECK (
    (`target_scope_flag` = 1 AND `target_resource_key` IS NOT NULL AND `target_operation` IS NOT NULL)
    OR (`target_scope_flag` = 0 AND `target_resource_key` IS NULL AND `target_operation` IS NULL)
  ),
  CONSTRAINT `chk_setting_definition_default` CHECK (`secret_flag` = 0 OR `default_json` IS NULL),
  CONSTRAINT `chk_setting_definition_status` CHECK (`status` IN ('active', 'retired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_setting_deployment_value' => <<<'SQL'
CREATE TABLE `pa_setting_deployment_value` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `definition_id` BIGINT UNSIGNED NOT NULL,
  `value_state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_json` JSON NULL,
  `ciphertext` VARBINARY(8192) NULL,
  `nonce` BINARY(24) NULL,
  `key_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `effective_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NULL,
  `updated_by_operator_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_deployment_value` (`definition_id`),
  CONSTRAINT `fk_setting_deployment_definition` FOREIGN KEY (`definition_id`) REFERENCES `pa_setting_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_deployment_operator` FOREIGN KEY (`updated_by_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_setting_deployment_state` CHECK (`value_state` IN ('set', 'unset')),
  CONSTRAINT `chk_setting_deployment_interval` CHECK (`expires_at` IS NULL OR `expires_at` > `effective_at`),
  CONSTRAINT `chk_setting_deployment_storage` CHECK (
    (`value_state` = 'unset' AND `value_json` IS NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
    OR (`value_state` = 'set' AND (
      (`value_json` IS NOT NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
      OR (`value_json` IS NULL AND `ciphertext` IS NOT NULL AND OCTET_LENGTH(`ciphertext`) > 0 AND `nonce` IS NOT NULL AND `key_id` IS NOT NULL)
    ))
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    ];

    private function __construct() {}

    /** @return list<string> */
    public static function tableNames(): array
    {
        return [
            'pa_setting_definition',
            'pa_setting_deployment_value',
            'pa_setting_tenant_value',
            'pa_setting_target_value',
        ];
    }

    public static function createSql(
        string $table,
        TenantPersistenceMode $mode = TenantPersistenceMode::TenantScoped,
    ): string
    {
        return match ($table) {
            'pa_setting_definition', 'pa_setting_deployment_value' => self::CREATE_SQL[$table],
            'pa_setting_tenant_value' => self::tenantValueSql($mode),
            'pa_setting_target_value' => self::targetValueSql($mode),
            default => throw new InvalidArgumentException("Unknown settings table: {$table}"),
        };
    }

    public static function dropSql(string $table): string
    {
        if (!in_array($table, self::tableNames(), true)) {
            throw new InvalidArgumentException("Unknown settings table: {$table}");
        }

        return "DROP TABLE IF EXISTS `{$table}`";
    }

    private static function tenantValueSql(TenantPersistenceMode $mode): string
    {
        $scope = new TenantColumnScope($mode);
        return sprintf(<<<'SQL'
CREATE TABLE `pa_setting_tenant_value` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
%s  `definition_id` BIGINT UNSIGNED NOT NULL,
  `value_state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_json` JSON NULL,
  `ciphertext` VARBINARY(8192) NULL,
  `nonce` BINARY(24) NULL,
  `key_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `effective_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_tenant_value` (%s`definition_id`),
  KEY `idx_setting_tenant_definition` (`definition_id`),
%s  CONSTRAINT `fk_setting_tenant_definition` FOREIGN KEY (`definition_id`) REFERENCES `pa_setting_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_tenant_member` FOREIGN KEY (`updated_by_member_id`) REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_setting_tenant_state` CHECK (`value_state` IN ('set', 'unset')),
  CONSTRAINT `chk_setting_tenant_interval` CHECK (`expires_at` IS NULL OR `expires_at` > `effective_at`),
  CONSTRAINT `chk_setting_tenant_storage` CHECK (
    (`value_state` = 'unset' AND `value_json` IS NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
    OR (`value_state` = 'set' AND (
      (`value_json` IS NOT NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
      OR (`value_json` IS NULL AND `ciphertext` IS NOT NULL AND OCTET_LENGTH(`ciphertext`) > 0 AND `nonce` IS NOT NULL AND `key_id` IS NOT NULL)
    ))
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            $scope->whenTenant("  `tenant_id` BIGINT UNSIGNED NOT NULL,\n"),
            $scope->whenTenant('`tenant_id`, '),
            $scope->whenTenant("  CONSTRAINT `fk_setting_tenant_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,\n"),
        );
    }

    private static function targetValueSql(TenantPersistenceMode $mode): string
    {
        $scope = new TenantColumnScope($mode);
        return sprintf(<<<'SQL'
CREATE TABLE `pa_setting_target_value` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
%s  `definition_id` BIGINT UNSIGNED NOT NULL,
  `target_resource_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_json` JSON NULL,
  `ciphertext` VARBINARY(8192) NULL,
  `nonce` BINARY(24) NULL,
  `key_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `effective_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_target_value` (%s`definition_id`, `target_resource_key`, `target_id`),
  KEY `idx_setting_target_definition` (`definition_id`),
%s  CONSTRAINT `fk_setting_target_definition` FOREIGN KEY (`definition_id`) REFERENCES `pa_setting_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_target_member` FOREIGN KEY (`updated_by_member_id`) REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_setting_target_state` CHECK (`value_state` IN ('set', 'unset')),
  CONSTRAINT `chk_setting_target_interval` CHECK (`expires_at` IS NULL OR `expires_at` > `effective_at`),
  CONSTRAINT `chk_setting_target_storage` CHECK (
    (`value_state` = 'unset' AND `value_json` IS NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
    OR (`value_state` = 'set' AND (
      (`value_json` IS NOT NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
      OR (`value_json` IS NULL AND `ciphertext` IS NOT NULL AND OCTET_LENGTH(`ciphertext`) > 0 AND `nonce` IS NOT NULL AND `key_id` IS NOT NULL)
    ))
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            $scope->whenTenant("  `tenant_id` BIGINT UNSIGNED NOT NULL,\n"),
            $scope->whenTenant('`tenant_id`, '),
            $scope->whenTenant("  CONSTRAINT `fk_setting_target_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,\n"),
        );
    }
}
