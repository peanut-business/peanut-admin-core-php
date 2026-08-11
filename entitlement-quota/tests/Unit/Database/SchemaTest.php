<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Tests\Unit\Database;

use InvalidArgumentException;
use PeanutAdmin\EntitlementQuota\Database\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testOwnsExactlyFiveReentrantMysqlTables(): void
    {
        self::assertSame([
            'pa_entitlement_grant',
            'pa_entitlement_policy_revision',
            'pa_entitlement_usage_window',
            'pa_entitlement_reservation',
            'pa_entitlement_usage_ledger',
        ], Schema::tableNames());
        self::assertCount(5, Schema::createSql());

        foreach (Schema::createSql() as $sql) {
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS', $sql);
            self::assertStringContainsString('ENGINE=InnoDB', $sql);
            self::assertStringNotContainsString('CASCADE', $sql);
        }
    }

    public function testSchemaFixesTenantSnapshotWindowReservationAndLedgerContracts(): void
    {
        $grant = Schema::createTableSql('pa_entitlement_grant');
        self::assertStringContainsString('UNIQUE KEY `uk_entitlement_grant_key` (`tenant_id`, `grant_key`)', $grant);
        self::assertStringContainsString("`state` IN ('active', 'suspended')", $grant);
        self::assertStringContainsString('FOREIGN KEY (`tenant_id`, `created_by_member_id`)', $grant);

        $policy = Schema::createTableSql('pa_entitlement_policy_revision');
        self::assertStringContainsString('UNIQUE KEY `uk_entitlement_policy_revision_key` (`tenant_id`, `policy_revision_key`)', $policy);
        self::assertStringContainsString('`canonical_snapshot_json` TEXT', $policy);
        self::assertStringContainsString('JSON_VALID(`canonical_snapshot_json`)', $policy);
        self::assertStringContainsString('`canonical_snapshot_sha256` CHAR(64)', $policy);
        self::assertStringContainsString("`period_kind` IN ('lifetime', 'utc_day', 'utc_month')", $policy);
        self::assertStringContainsString('`reservation_ttl_seconds` BETWEEN 30 AND 86400', $policy);

        $window = Schema::createTableSql('pa_entitlement_usage_window');
        self::assertStringContainsString('UNIQUE KEY `uk_entitlement_window_identity`', $window);
        self::assertStringContainsString('`committed_amount` BIGINT NOT NULL DEFAULT 0', $window);
        self::assertStringContainsString('FOREIGN KEY (`tenant_id`, `policy_revision_id`)', $window);

        $reservation = Schema::createTableSql('pa_entitlement_reservation');
        self::assertStringContainsString("`state` IN ('pending', 'committed', 'released', 'expired')", $reservation);
        self::assertStringContainsString("`reservation_key` REGEXP '^reservation_[0-9a-f]{32}$'", $reservation);
        self::assertStringContainsString('`state` = \'pending\'', $reservation);
        self::assertStringContainsString("`state` IN ('committed', 'released', 'expired')", $reservation);

        $ledger = Schema::createTableSql('pa_entitlement_usage_ledger');
        self::assertStringContainsString('UNIQUE KEY `uk_entitlement_ledger_event_key`', $ledger);
        self::assertStringContainsString('UNIQUE KEY `uk_entitlement_ledger_reservation_event`', $ledger);
        self::assertStringContainsString("`event_type` IN ('reserved', 'committed', 'released', 'expired')", $ledger);
        self::assertStringNotContainsString('payload', $ledger);
    }

    public function testUnknownTableFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Schema::createTableSql('pa_subscription_plan');
    }
}
