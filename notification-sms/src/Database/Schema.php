<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Database;

use InvalidArgumentException;

final class Schema
{
    /** @return list<string> */
    public static function tableNames(): array
    {
        return [
            'pa_notification_template',
            'pa_notification_message',
            'pa_notification_attachment',
            'pa_notification_outbox',
            'pa_sms_rate_bucket',
            'pa_notification_event',
        ];
    }

    public static function createSql(string $table): string
    {
        return match ($table) {
            'pa_notification_template' => <<<'SQL'
CREATE TABLE `pa_notification_template` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `template_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `subject_template` VARCHAR(255) NOT NULL,
  `body_template` TEXT NOT NULL,
  `channels_json` JSON NOT NULL,
  `variable_keys_json` JSON NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notification_template` (`tenant_id`, `template_key`),
  KEY `idx_notification_template_status` (`tenant_id`, `status`, `id`),
  CONSTRAINT `fk_notification_template_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_notification_template_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_notification_template_key` CHECK (`template_key` REGEXP '^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_notification_template_status` CHECK (`status` IN ('active','inactive')),
  CONSTRAINT `chk_notification_template_revision` CHECK (`revision` >= 1)
) ENGINE=InnoDB
SQL,
            'pa_notification_message' => <<<'SQL'
CREATE TABLE `pa_notification_message` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `template_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `template_revision` BIGINT UNSIGNED NOT NULL,
  `recipient_member_id` BIGINT UNSIGNED NOT NULL,
  `recipient_account_id` BIGINT UNSIGNED NOT NULL,
  `recipient_display_name` VARCHAR(160) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unread',
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `read_at` DATETIME(3) NULL,
  `archived_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notification_message_key` (`message_key`),
  UNIQUE KEY `uk_notification_message_tenant_id` (`tenant_id`, `id`),
  KEY `idx_notification_inbox` (`tenant_id`, `recipient_member_id`, `status`, `id`),
  KEY `idx_notification_template` (`tenant_id`, `template_key`, `id`),
  CONSTRAINT `fk_notification_message_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_notification_recipient_member` FOREIGN KEY (`tenant_id`, `recipient_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_notification_creator_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_notification_message_key` CHECK (`message_key` REGEXP '^notice_[0-9a-f]{32}$'),
  CONSTRAINT `chk_notification_message_template_revision` CHECK (`template_revision` >= 1),
  CONSTRAINT `chk_notification_message_status` CHECK (`status` IN ('unread','read','archived')),
  CONSTRAINT `chk_notification_message_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_notification_message_read` CHECK ((`status` = 'unread') = (`read_at` IS NULL)),
  CONSTRAINT `chk_notification_message_archive` CHECK ((`status` = 'archived') = (`archived_at` IS NOT NULL))
) ENGINE=InnoDB
SQL,
            'pa_notification_attachment' => <<<'SQL'
CREATE TABLE `pa_notification_attachment` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `media_type` VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL,
  `sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notification_attachment` (`tenant_id`, `message_id`, `file_key`),
  CONSTRAINT `fk_notification_attachment_message` FOREIGN KEY (`tenant_id`, `message_id`) REFERENCES `pa_notification_message` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_notification_attachment_file` CHECK (`file_key` REGEXP '^file_[0-9a-f]{32}$'),
  CONSTRAINT `chk_notification_attachment_sha` CHECK (`sha256` REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB
SQL,
            'pa_notification_outbox' => <<<'SQL'
CREATE TABLE `pa_notification_outbox` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `outbox_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `channel` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `recipient_phone_masked` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `recipient_phone_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `dispatch_job_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `provider_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `provider_message_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `provider_receipt_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `delivered_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notification_outbox_key` (`outbox_key`),
  UNIQUE KEY `uk_notification_outbox_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_notification_outbox_channel` (`tenant_id`, `message_id`, `channel`),
  KEY `idx_notification_outbox_pending` (`tenant_id`, `status`, `id`),
  CONSTRAINT `fk_notification_outbox_message` FOREIGN KEY (`tenant_id`, `message_id`) REFERENCES `pa_notification_message` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_notification_outbox_key` CHECK (`outbox_key` REGEXP '^outbox_[0-9a-f]{32}$'),
  CONSTRAINT `chk_notification_outbox_channel` CHECK (`channel` IN ('inbox','sms')),
  CONSTRAINT `chk_notification_outbox_status` CHECK (`status` IN ('pending','queued','processing','retryable','delivered','permanent_failed')),
  CONSTRAINT `chk_notification_outbox_phone` CHECK ((`channel` = 'sms') = (`recipient_phone_masked` IS NOT NULL AND `recipient_phone_digest` IS NOT NULL)),
  CONSTRAINT `chk_notification_outbox_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_notification_outbox_delivery` CHECK ((`status` = 'delivered') = (`delivered_at` IS NOT NULL))
) ENGINE=InnoDB
SQL,
            'pa_sms_rate_bucket' => <<<'SQL'
CREATE TABLE `pa_sms_rate_bucket` (
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `bucket_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `window_seconds` SMALLINT UNSIGNED NOT NULL,
  `window_started_at` DATETIME(3) NOT NULL,
  `send_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`tenant_id`, `bucket_key`),
  CONSTRAINT `fk_sms_rate_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_sms_rate_bucket` CHECK (`bucket_key` REGEXP '^(tenant|recipient:[0-9a-f]{64})$'),
  CONSTRAINT `chk_sms_rate_window` CHECK (`window_seconds` BETWEEN 1 AND 3600)
) ENGINE=InnoDB
SQL,
            'pa_notification_event' => <<<'SQL'
CREATE TABLE `pa_notification_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `event_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_member_id` BIGINT UNSIGNED NULL,
  `metadata_json` JSON NOT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notification_event_message` (`tenant_id`, `message_id`, `id`),
  KEY `idx_notification_event_time` (`tenant_id`, `occurred_at`, `id`),
  CONSTRAINT `fk_notification_event_message` FOREIGN KEY (`tenant_id`, `message_id`) REFERENCES `pa_notification_message` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_notification_event_member` FOREIGN KEY (`tenant_id`, `actor_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_notification_event_key` CHECK (`event_key` REGEXP '^tenant\\.notification\\.[a-z_]+$')
) ENGINE=InnoDB
SQL,
            default => throw new InvalidArgumentException('Unknown notification-sms table.'),
        };
    }

    public static function dropSql(string $table): string
    {
        if (!in_array($table, self::tableNames(), true)) {
            throw new InvalidArgumentException('Unknown notification-sms table.');
        }

        return sprintf('DROP TABLE IF EXISTS `%s`', $table);
    }
}
