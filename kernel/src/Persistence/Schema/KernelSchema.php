<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Schema;

use InvalidArgumentException;

final class KernelSchema
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_account' => <<<'SQL'
CREATE TABLE `pa_account` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `display_name` VARCHAR(120) NOT NULL,
  `avatar_uri` VARCHAR(512) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `security_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `locked_until` DATETIME(3) NULL,
  `last_login_at` DATETIME(3) NULL,
  `closed_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_status` (`status`, `id`),
  CONSTRAINT `chk_account_status` CHECK (`status` IN ('active', 'locked', 'disabled', 'closed')),
  CONSTRAINT `chk_account_closed` CHECK ((`status` = 'closed' AND `closed_at` IS NOT NULL) OR `status` <> 'closed')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_credential' => <<<'SQL'
CREATE TABLE `pa_credential` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `kind` VARCHAR(32) NOT NULL,
  `identifier_type` VARCHAR(32) NOT NULL,
  `identifier_normalized` VARCHAR(255) NOT NULL,
  `secret_hash` VARCHAR(255) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME(3) NULL,
  `verified_at` DATETIME(3) NOT NULL,
  `last_used_at` DATETIME(3) NULL,
  `secret_changed_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `revoked_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_credential_identifier` (`identifier_type`, `identifier_normalized`),
  KEY `idx_credential_account` (`account_id`, `status`),
  CONSTRAINT `fk_credential_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_credential_kind` CHECK (`kind` = 'email_password'),
  CONSTRAINT `chk_credential_identifier_type` CHECK (`identifier_type` = 'email'),
  CONSTRAINT `chk_credential_status` CHECK (`status` IN ('active', 'locked', 'revoked')),
  CONSTRAINT `chk_credential_revoked` CHECK ((`status` = 'revoked' AND `revoked_at` IS NOT NULL) OR `status` <> 'revoked')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_tenant' => <<<'SQL'
CREATE TABLE `pa_tenant` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `display_name` VARCHAR(160) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'provisioning',
  `locale` VARCHAR(16) NOT NULL DEFAULT 'zh-CN',
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'Asia/Shanghai',
  `security_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `authorization_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `activated_at` DATETIME(3) NULL,
  `suspended_at` DATETIME(3) NULL,
  `closed_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`code`),
  KEY `idx_tenant_status` (`status`, `id`),
  CONSTRAINT `chk_tenant_status` CHECK (`status` IN ('provisioning', 'active', 'suspended', 'closed')),
  CONSTRAINT `chk_tenant_closed` CHECK ((`status` = 'closed' AND `closed_at` IS NOT NULL) OR `status` <> 'closed')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_platform_operator' => <<<'SQL'
CREATE TABLE `pa_platform_operator` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `display_name` VARCHAR(120) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `security_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `suspended_at` DATETIME(3) NULL,
  `closed_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_operator_account` (`account_id`),
  KEY `idx_platform_operator_status` (`status`, `id`),
  CONSTRAINT `fk_platform_operator_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_platform_operator_status` CHECK (`status` IN ('active', 'suspended', 'closed')),
  CONSTRAINT `chk_platform_operator_closed` CHECK ((`status` = 'closed' AND `closed_at` IS NOT NULL) OR `status` <> 'closed')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_permission' => <<<'SQL'
CREATE TABLE `pa_permission` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(160) NOT NULL,
  `module_key` VARCHAR(96) NOT NULL,
  `type` VARCHAR(32) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `description` VARCHAR(500) NULL,
  `risk_level` VARCHAR(16) NOT NULL DEFAULT 'normal',
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `manifest_version` VARCHAR(32) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `retired_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_permission_key` (`key`),
  KEY `idx_permission_module` (`module_key`, `status`, `type`),
  CONSTRAINT `chk_permission_type` CHECK (`type` IN ('menu', 'action', 'api')),
  CONSTRAINT `chk_permission_risk` CHECK (`risk_level` IN ('normal', 'sensitive', 'critical')),
  CONSTRAINT `chk_permission_status` CHECK (`status` IN ('active', 'retired')),
  CONSTRAINT `chk_permission_retired` CHECK ((`status` = 'retired' AND `retired_at` IS NOT NULL) OR `status` <> 'retired')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_platform_role' => <<<'SQL'
CREATE TABLE `pa_platform_role` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(96) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `description` VARCHAR(500) NULL,
  `is_builtin` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `archived_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_role_key` (`key`),
  KEY `idx_platform_role_status` (`status`, `id`),
  CONSTRAINT `chk_platform_role_builtin` CHECK (`is_builtin` IN (0, 1)),
  CONSTRAINT `chk_platform_role_status` CHECK (`status` IN ('active', 'disabled', 'archived'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_platform_role_permission' => <<<'SQL'
CREATE TABLE `pa_platform_role_permission` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_role_id` BIGINT UNSIGNED NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  `granted_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_role_permission` (`platform_role_id`, `permission_id`),
  CONSTRAINT `fk_platform_role_permission_role` FOREIGN KEY (`platform_role_id`) REFERENCES `pa_platform_role` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_role_permission_permission` FOREIGN KEY (`permission_id`) REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_platform_operator_role' => <<<'SQL'
CREATE TABLE `pa_platform_operator_role` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_operator_id` BIGINT UNSIGNED NOT NULL,
  `platform_role_id` BIGINT UNSIGNED NOT NULL,
  `assigned_by_operator_id` BIGINT UNSIGNED NULL,
  `assigned_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_operator_role` (`platform_operator_id`, `platform_role_id`),
  CONSTRAINT `fk_platform_operator_role_operator` FOREIGN KEY (`platform_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_operator_role_role` FOREIGN KEY (`platform_role_id`) REFERENCES `pa_platform_role` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_operator_role_assigner` FOREIGN KEY (`assigned_by_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_department' => <<<'SQL'
CREATE TABLE `pa_department` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `parent_id` BIGINT UNSIGNED NULL,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `archived_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_department_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_department_code` (`tenant_id`, `code`),
  KEY `idx_department_parent` (`tenant_id`, `parent_id`, `status`, `sort_order`),
  CONSTRAINT `fk_department_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_department_parent` FOREIGN KEY (`tenant_id`, `parent_id`) REFERENCES `pa_department` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_department_status` CHECK (`status` IN ('active', 'disabled', 'archived'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_tenant_member' => <<<'SQL'
CREATE TABLE `pa_tenant_member` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `member_no` VARCHAR(64) NULL,
  `display_name` VARCHAR(120) NULL,
  `member_type` VARCHAR(32) NOT NULL DEFAULT 'internal',
  `primary_department_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `security_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `authorization_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `joined_at` DATETIME(3) NULL,
  `suspended_at` DATETIME(3) NULL,
  `left_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_member_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_tenant_member_account` (`tenant_id`, `account_id`),
  UNIQUE KEY `uk_tenant_member_no` (`tenant_id`, `member_no`),
  KEY `idx_member_department` (`tenant_id`, `primary_department_id`, `status`),
  CONSTRAINT `fk_tenant_member_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tenant_member_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_member_type` CHECK (`member_type` IN ('internal', 'external')),
  CONSTRAINT `chk_tenant_member_status` CHECK (`status` IN ('pending', 'active', 'suspended', 'left'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_role' => <<<'SQL'
CREATE TABLE `pa_role` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `key` VARCHAR(96) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `description` VARCHAR(500) NULL,
  `is_builtin` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `authorization_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `archived_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_role_key` (`tenant_id`, `key`),
  KEY `idx_role_status` (`tenant_id`, `status`, `id`),
  CONSTRAINT `fk_role_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_role_builtin` CHECK (`is_builtin` IN (0, 1)),
  CONSTRAINT `chk_role_status` CHECK (`status` IN ('active', 'disabled', 'archived'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_role_permission' => <<<'SQL'
CREATE TABLE `pa_role_permission` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `role_id` BIGINT UNSIGNED NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  `granted_by_member_id` BIGINT UNSIGNED NULL,
  `granted_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_permission` (`tenant_id`, `role_id`, `permission_id`),
  CONSTRAINT `fk_role_permission_role` FOREIGN KEY (`tenant_id`, `role_id`) REFERENCES `pa_role` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_role_permission_permission` FOREIGN KEY (`permission_id`) REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_role_permission_granter` FOREIGN KEY (`tenant_id`, `granted_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_member_role' => <<<'SQL'
CREATE TABLE `pa_member_role` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `tenant_member_id` BIGINT UNSIGNED NOT NULL,
  `role_id` BIGINT UNSIGNED NOT NULL,
  `assigned_by_member_id` BIGINT UNSIGNED NULL,
  `assigned_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_role` (`tenant_id`, `tenant_member_id`, `role_id`),
  CONSTRAINT `fk_member_role_member` FOREIGN KEY (`tenant_id`, `tenant_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_role_role` FOREIGN KEY (`tenant_id`, `role_id`) REFERENCES `pa_role` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_role_assigner` FOREIGN KEY (`tenant_id`, `assigned_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_tenant_module' => <<<'SQL'
CREATE TABLE `pa_tenant_module` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `module_key` VARCHAR(96) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'disabled',
  `source` VARCHAR(32) NOT NULL DEFAULT 'manual',
  `config_json` JSON NULL,
  `config_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `authorization_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `effective_at` DATETIME(3) NULL,
  `expires_at` DATETIME(3) NULL,
  `enabled_at` DATETIME(3) NULL,
  `disabled_at` DATETIME(3) NULL,
  `disabled_reason` VARCHAR(255) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_module` (`tenant_id`, `module_key`),
  KEY `idx_tenant_module_status` (`tenant_id`, `status`, `module_key`),
  CONSTRAINT `fk_tenant_module_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_module_status` CHECK (`status` IN ('disabled', 'enabled', 'expired')),
  CONSTRAINT `chk_tenant_module_source` CHECK (`source` IN ('manual', 'product_profile', 'license')),
  CONSTRAINT `chk_tenant_module_dates` CHECK (`expires_at` IS NULL OR `effective_at` IS NULL OR `expires_at` > `effective_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_platform_audit_event' => <<<'SQL'
CREATE TABLE `pa_platform_audit_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` VARCHAR(96) NOT NULL,
  `action` VARCHAR(160) NOT NULL,
  `outcome` VARCHAR(16) NOT NULL,
  `reason_code` VARCHAR(96) NULL,
  `operator_id` BIGINT UNSIGNED NULL,
  `account_id` BIGINT UNSIGNED NULL,
  `target_type` VARCHAR(96) NULL,
  `target_id` VARCHAR(128) NULL,
  `request_id` VARCHAR(64) NOT NULL,
  `operation_id` VARCHAR(64) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent_hash` CHAR(64) NULL,
  `before_json` JSON NULL,
  `after_json` JSON NULL,
  `metadata_json` JSON NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_platform_audit_time` (`occurred_at`, `id`),
  KEY `idx_platform_audit_operator` (`operator_id`, `occurred_at`),
  KEY `idx_platform_audit_target` (`target_type`, `target_id`, `occurred_at`),
  KEY `idx_platform_audit_request` (`request_id`),
  CONSTRAINT `chk_platform_audit_outcome` CHECK (`outcome` IN ('success', 'denied', 'error'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_tenant_audit_event' => <<<'SQL'
CREATE TABLE `pa_tenant_audit_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `event_type` VARCHAR(96) NOT NULL,
  `action` VARCHAR(160) NOT NULL,
  `outcome` VARCHAR(16) NOT NULL,
  `reason_code` VARCHAR(96) NULL,
  `actor_tenant_id` BIGINT UNSIGNED NULL,
  `actor_tenant_member_id` BIGINT UNSIGNED NULL,
  `actor_account_id` BIGINT UNSIGNED NULL,
  `actor_platform_operator_id` BIGINT UNSIGNED NULL,
  `actor_type` VARCHAR(32) NOT NULL,
  `target_resource_type` VARCHAR(160) NULL,
  `target_resource_id` VARCHAR(128) NULL,
  `boundary_target_type` VARCHAR(160) NULL,
  `boundary_target_id` VARCHAR(128) NULL,
  `target_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `target_set_digest` CHAR(64) NULL,
  `authorization_basis_json` JSON NULL,
  `request_id` VARCHAR(64) NOT NULL,
  `operation_id` VARCHAR(64) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent_hash` CHAR(64) NULL,
  `before_json` JSON NULL,
  `after_json` JSON NULL,
  `metadata_json` JSON NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_audit_time` (`tenant_id`, `occurred_at`, `id`),
  KEY `idx_tenant_audit_member` (`tenant_id`, `actor_tenant_member_id`, `occurred_at`),
  KEY `idx_tenant_audit_actor_tenant` (`actor_tenant_id`, `occurred_at`, `id`),
  KEY `idx_tenant_audit_target` (`tenant_id`, `target_resource_type`, `target_resource_id`, `occurred_at`),
  KEY `idx_tenant_audit_boundary_target` (`tenant_id`, `boundary_target_type`, `boundary_target_id`, `occurred_at`),
  KEY `idx_tenant_audit_request` (`request_id`),
  CONSTRAINT `chk_tenant_audit_outcome` CHECK (`outcome` IN ('success', 'denied', 'error')),
  CONSTRAINT `chk_tenant_audit_actor_type` CHECK (`actor_type` IN ('member', 'tenant_system', 'platform_operator')),
  CONSTRAINT `chk_tenant_audit_actor` CHECK (
    (`actor_type` = 'member' AND `actor_tenant_id` = `tenant_id` AND `actor_tenant_member_id` IS NOT NULL AND `actor_account_id` IS NOT NULL AND `actor_platform_operator_id` IS NULL)
    OR (`actor_type` = 'tenant_system' AND `actor_tenant_id` = `tenant_id` AND `actor_tenant_member_id` IS NULL AND `actor_account_id` IS NULL AND `actor_platform_operator_id` IS NULL)
    OR (`actor_type` = 'platform_operator' AND `actor_tenant_id` IS NULL AND `actor_tenant_member_id` IS NULL AND `actor_account_id` IS NOT NULL AND `actor_platform_operator_id` IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_login_challenge' => <<<'SQL'
CREATE TABLE `pa_login_challenge` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `challenge_key` CHAR(26) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `purpose` VARCHAR(32) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `source_session_key` CHAR(26) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent_hash` CHAR(64) NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `used_at` DATETIME(3) NULL,
  `revoked_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_login_challenge_key` (`challenge_key`),
  UNIQUE KEY `uk_login_challenge_token` (`token_hash`),
  KEY `idx_login_challenge_account` (`account_id`, `status`, `expires_at`),
  CONSTRAINT `fk_login_challenge_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_login_challenge_purpose` CHECK (`purpose` IN ('tenant_login', 'tenant_switch')),
  CONSTRAINT `chk_login_challenge_status` CHECK (`status` IN ('active', 'used', 'revoked', 'expired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_tenant_session' => <<<'SQL'
CREATE TABLE `pa_tenant_session` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_key` CHAR(26) NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `tenant_member_id` BIGINT UNSIGNED NOT NULL,
  `client_key` VARCHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `account_security_revision` BIGINT UNSIGNED NOT NULL,
  `tenant_security_revision` BIGINT UNSIGNED NOT NULL,
  `member_security_revision` BIGINT UNSIGNED NOT NULL,
  `issued_at` DATETIME(3) NOT NULL,
  `last_seen_at` DATETIME(3) NOT NULL,
  `idle_expires_at` DATETIME(3) NOT NULL,
  `absolute_expires_at` DATETIME(3) NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent_hash` CHAR(64) NULL,
  `revoked_at` DATETIME(3) NULL,
  `revoke_reason` VARCHAR(64) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_session_key` (`session_key`),
  KEY `idx_tenant_session_member` (`tenant_id`, `tenant_member_id`, `status`, `absolute_expires_at`),
  KEY `idx_tenant_session_account` (`account_id`, `status`, `absolute_expires_at`),
  CONSTRAINT `fk_tenant_session_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tenant_session_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tenant_session_member` FOREIGN KEY (`tenant_id`, `tenant_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_session_client` CHECK (`client_key` = 'admin-web'),
  CONSTRAINT `chk_tenant_session_status` CHECK (`status` IN ('active', 'revoked', 'expired')),
  CONSTRAINT `chk_tenant_session_expiry` CHECK (`idle_expires_at` <= `absolute_expires_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_tenant_session_token' => <<<'SQL'
CREATE TABLE `pa_tenant_session_token` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `token_type` VARCHAR(16) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `parent_token_id` BIGINT UNSIGNED NULL,
  `replaced_by_token_id` BIGINT UNSIGNED NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `used_at` DATETIME(3) NULL,
  `revoked_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_session_token_hash` (`token_hash`),
  KEY `idx_tenant_session_token_active` (`session_id`, `token_type`, `status`, `expires_at`),
  CONSTRAINT `fk_tenant_token_session` FOREIGN KEY (`session_id`) REFERENCES `pa_tenant_session` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tenant_token_parent` FOREIGN KEY (`parent_token_id`) REFERENCES `pa_tenant_session_token` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tenant_token_replacement` FOREIGN KEY (`replaced_by_token_id`) REFERENCES `pa_tenant_session_token` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_token_type` CHECK (`token_type` IN ('access', 'refresh')),
  CONSTRAINT `chk_tenant_token_status` CHECK (`status` IN ('active', 'used', 'revoked', 'expired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_platform_session' => <<<'SQL'
CREATE TABLE `pa_platform_session` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_key` CHAR(26) NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `platform_operator_id` BIGINT UNSIGNED NOT NULL,
  `client_key` VARCHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `account_security_revision` BIGINT UNSIGNED NOT NULL,
  `operator_security_revision` BIGINT UNSIGNED NOT NULL,
  `issued_at` DATETIME(3) NOT NULL,
  `last_seen_at` DATETIME(3) NOT NULL,
  `idle_expires_at` DATETIME(3) NOT NULL,
  `absolute_expires_at` DATETIME(3) NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent_hash` CHAR(64) NULL,
  `revoked_at` DATETIME(3) NULL,
  `revoke_reason` VARCHAR(64) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_session_key` (`session_key`),
  KEY `idx_platform_session_operator` (`platform_operator_id`, `status`, `absolute_expires_at`),
  KEY `idx_platform_session_account` (`account_id`, `status`, `absolute_expires_at`),
  CONSTRAINT `fk_platform_session_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_session_operator` FOREIGN KEY (`platform_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_platform_session_client` CHECK (`client_key` = 'platform-web'),
  CONSTRAINT `chk_platform_session_status` CHECK (`status` IN ('active', 'revoked', 'expired')),
  CONSTRAINT `chk_platform_session_expiry` CHECK (`idle_expires_at` <= `absolute_expires_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_platform_session_token' => <<<'SQL'
CREATE TABLE `pa_platform_session_token` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `token_type` VARCHAR(16) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `parent_token_id` BIGINT UNSIGNED NULL,
  `replaced_by_token_id` BIGINT UNSIGNED NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `used_at` DATETIME(3) NULL,
  `revoked_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_session_token_hash` (`token_hash`),
  KEY `idx_platform_session_token_active` (`session_id`, `token_type`, `status`, `expires_at`),
  CONSTRAINT `fk_platform_token_session` FOREIGN KEY (`session_id`) REFERENCES `pa_platform_session` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_token_parent` FOREIGN KEY (`parent_token_id`) REFERENCES `pa_platform_session_token` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_token_replacement` FOREIGN KEY (`replaced_by_token_id`) REFERENCES `pa_platform_session_token` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_platform_token_type` CHECK (`token_type` IN ('access', 'refresh')),
  CONSTRAINT `chk_platform_token_status` CHECK (`status` IN ('active', 'used', 'revoked', 'expired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_auth_security_event' => <<<'SQL'
CREATE TABLE `pa_auth_security_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `audience` VARCHAR(16) NOT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `outcome` VARCHAR(16) NOT NULL,
  `reason_code` VARCHAR(96) NULL,
  `account_id` BIGINT UNSIGNED NULL,
  `credential_id` BIGINT UNSIGNED NULL,
  `session_key` CHAR(26) NULL,
  `identifier_hmac` CHAR(64) NULL,
  `request_id` VARCHAR(64) NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent_hash` CHAR(64) NULL,
  `metadata_json` JSON NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_auth_event_account` (`account_id`, `occurred_at`),
  KEY `idx_auth_event_identifier` (`identifier_hmac`, `occurred_at`),
  KEY `idx_auth_event_request` (`request_id`),
  KEY `idx_auth_event_time` (`occurred_at`, `id`),
  CONSTRAINT `chk_auth_event_audience` CHECK (`audience` IN ('tenant', 'platform')),
  CONSTRAINT `chk_auth_event_outcome` CHECK (`outcome` IN ('success', 'denied', 'error'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_ops_task' => <<<'SQL'
CREATE TABLE `pa_ops_task` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `task_type` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `handler_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_json` JSON NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'queued',
  `attempt_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` TINYINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `idempotency_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `concurrency_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `submitted_by_operator_id` BIGINT UNSIGNED NOT NULL,
  `available_at` DATETIME(3) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_task_key` (`task_key`),
  UNIQUE KEY `uk_ops_task_idempotency` (`submitted_by_operator_id`, `idempotency_digest`),
  KEY `idx_ops_task_status` (`status`, `available_at`, `id`),
  KEY `idx_ops_task_concurrency` (`concurrency_key`, `status`, `id`),
  CONSTRAINT `fk_ops_task_operator` FOREIGN KEY (`submitted_by_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ops_task_type` CHECK (`task_type` IN ('ops.backup.create','ops.restore.verify')),
  CONSTRAINT `chk_ops_task_status` CHECK (`status` IN ('queued','running','succeeded','dead','cancelled')),
  CONSTRAINT `chk_ops_task_attempts` CHECK (`max_attempts` BETWEEN 1 AND 10 AND `attempt_count` <= `max_attempts`),
  CONSTRAINT `chk_ops_task_completion` CHECK ((`status` IN ('succeeded','dead','cancelled')) = (`completed_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_ops_maintenance_window' => <<<'SQL'
CREATE TABLE `pa_ops_maintenance_window` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `maintenance_key` CHAR(44) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state` VARCHAR(16) NOT NULL,
  `reason_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `starts_at` DATETIME(3) NOT NULL,
  `ends_at` DATETIME(3) NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `idempotency_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by_operator_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `closed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_maintenance_key` (`maintenance_key`),
  UNIQUE KEY `uk_ops_maintenance_idempotency` (`created_by_operator_id`, `idempotency_digest`),
  KEY `idx_ops_maintenance_state` (`state`, `starts_at`, `id`),
  CONSTRAINT `fk_ops_maintenance_operator` FOREIGN KEY (`created_by_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ops_maintenance_state` CHECK (`state` IN ('scheduled','active','closed')),
  CONSTRAINT `chk_ops_maintenance_range` CHECK (`ends_at` > `starts_at`)
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
        $sql = self::CREATE_SQL[$table] ?? null;
        if ($sql === null) {
            throw new InvalidArgumentException("Unknown Kernel table: {$table}");
        }

        return $sql;
    }

    public static function dropSql(string $table): string
    {
        if (!isset(self::CREATE_SQL[$table])) {
            throw new InvalidArgumentException("Unknown Kernel table: {$table}");
        }

        return "DROP TABLE IF EXISTS `{$table}`";
    }

    public static function addTenantMemberDepartmentForeignKeySql(): string
    {
        return <<<'SQL'
ALTER TABLE `pa_tenant_member`
  ADD CONSTRAINT `fk_tenant_member_department`
  FOREIGN KEY (`tenant_id`, `primary_department_id`)
  REFERENCES `pa_department` (`tenant_id`, `id`)
  ON DELETE RESTRICT
SQL;
    }

    public static function dropTenantMemberDepartmentForeignKeySql(): string
    {
        return 'ALTER TABLE `pa_tenant_member` DROP FOREIGN KEY `fk_tenant_member_department`';
    }
}
