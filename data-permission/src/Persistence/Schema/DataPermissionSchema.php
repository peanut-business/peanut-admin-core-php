<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Persistence\Schema;

use InvalidArgumentException;

final class DataPermissionSchema
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_data_permission_policy' => <<<'SQL'
CREATE TABLE `pa_data_permission_policy` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `role_id` BIGINT UNSIGNED NOT NULL,
  `protected_resource_id` BIGINT UNSIGNED NOT NULL,
  `resource_operation_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `valid_from` DATETIME(3) NULL,
  `valid_until` DATETIME(3) NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `reason` VARCHAR(300) NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `archived_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_policy_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_data_policy` (`tenant_id`, `role_id`, `resource_operation_id`),
  KEY `idx_data_policy_active` (`tenant_id`, `role_id`, `status`, `valid_until`),
  CONSTRAINT `fk_data_policy_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_policy_role` FOREIGN KEY (`tenant_id`, `role_id`) REFERENCES `pa_role` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_policy_resource` FOREIGN KEY (`protected_resource_id`) REFERENCES `pa_protected_resource` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_policy_operation` FOREIGN KEY (`resource_operation_id`) REFERENCES `pa_resource_operation` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_policy_creator` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_policy_updater` FOREIGN KEY (`tenant_id`, `updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_data_policy_status` CHECK (`status` IN ('active', 'disabled', 'archived')),
  CONSTRAINT `chk_data_policy_dates` CHECK (`valid_until` IS NULL OR `valid_from` IS NULL OR `valid_until` > `valid_from`),
  CONSTRAINT `chk_data_policy_archived` CHECK ((`status` = 'archived' AND `archived_at` IS NOT NULL) OR `status` <> 'archived')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_data_permission_group' => <<<'SQL'
CREATE TABLE `pa_data_permission_group` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `data_permission_policy_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `match_mode` VARCHAR(8) NOT NULL DEFAULT 'all',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_group_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_data_group_name` (`tenant_id`, `data_permission_policy_id`, `name`),
  CONSTRAINT `fk_data_group_policy` FOREIGN KEY (`tenant_id`, `data_permission_policy_id`) REFERENCES `pa_data_permission_policy` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_data_group_match` CHECK (`match_mode` = 'all'),
  CONSTRAINT `chk_data_group_status` CHECK (`status` IN ('active', 'disabled'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_data_permission_target_set' => <<<'SQL'
CREATE TABLE `pa_data_permission_target_set` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `target_mode` VARCHAR(32) NOT NULL,
  `target_resource_key` VARCHAR(160) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `archived_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_target_set_tenant_id` (`tenant_id`, `id`),
  KEY `idx_data_target_set_status` (`tenant_id`, `target_resource_key`, `status`, `id`),
  CONSTRAINT `fk_data_target_set_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_target_set_creator` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_target_set_updater` FOREIGN KEY (`tenant_id`, `updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_data_target_set_mode` CHECK (`target_mode` IN ('department', 'resource')),
  CONSTRAINT `chk_data_target_set_resource` CHECK ((`target_mode` = 'department' AND `target_resource_key` = 'core.department') OR (`target_mode` = 'resource' AND `target_resource_key` <> 'core.department')),
  CONSTRAINT `chk_data_target_set_status` CHECK (`status` IN ('active', 'disabled', 'archived')),
  CONSTRAINT `chk_data_target_set_archived` CHECK ((`status` = 'archived' AND `archived_at` IS NOT NULL) OR `status` <> 'archived')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_data_permission_condition' => <<<'SQL'
CREATE TABLE `pa_data_permission_condition` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `data_permission_group_id` BIGINT UNSIGNED NOT NULL,
  `condition_definition_id` BIGINT UNSIGNED NOT NULL,
  `target_set_id` BIGINT UNSIGNED NULL,
  `target_set_key` BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(`target_set_id`, 0)) STORED,
  `config_json` JSON NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_condition` (`tenant_id`, `data_permission_group_id`, `condition_definition_id`, `target_set_key`),
  CONSTRAINT `fk_data_condition_group` FOREIGN KEY (`tenant_id`, `data_permission_group_id`) REFERENCES `pa_data_permission_group` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_condition_definition` FOREIGN KEY (`condition_definition_id`) REFERENCES `pa_data_condition_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_condition_target_set` FOREIGN KEY (`tenant_id`, `target_set_id`) REFERENCES `pa_data_permission_target_set` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_data_permission_condition_status` CHECK (`status` IN ('active', 'disabled'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_data_permission_target' => <<<'SQL'
CREATE TABLE `pa_data_permission_target` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `target_set_id` BIGINT UNSIGNED NOT NULL,
  `target_id` VARCHAR(128) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `added_by_member_id` BIGINT UNSIGNED NOT NULL,
  `removed_by_member_id` BIGINT UNSIGNED NULL,
  `added_at` DATETIME(3) NOT NULL,
  `removed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_target` (`tenant_id`, `target_set_id`, `target_id`),
  KEY `idx_data_target_active` (`tenant_id`, `target_set_id`, `status`, `target_id`),
  CONSTRAINT `fk_data_target_set` FOREIGN KEY (`tenant_id`, `target_set_id`) REFERENCES `pa_data_permission_target_set` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_target_adder` FOREIGN KEY (`tenant_id`, `added_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_target_remover` FOREIGN KEY (`tenant_id`, `removed_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_data_target_status` CHECK (`status` IN ('active', 'removed')),
  CONSTRAINT `chk_data_target_removed` CHECK ((`status` = 'removed' AND `removed_at` IS NOT NULL AND `removed_by_member_id` IS NOT NULL) OR `status` <> 'removed')
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
        return self::CREATE_SQL[$table] ?? throw new InvalidArgumentException("Unknown data-permission table: {$table}");
    }

    public static function dropSql(string $table): string
    {
        if (!isset(self::CREATE_SQL[$table])) {
            throw new InvalidArgumentException("Unknown data-permission table: {$table}");
        }

        return "DROP TABLE IF EXISTS `{$table}`";
    }
}
