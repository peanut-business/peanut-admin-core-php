<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Tests\Unit\Database;

use InvalidArgumentException;
use PeanutAdmin\ArtifactRevision\Database\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testOwnsExactlyTwoReentrantMysqlTables(): void
    {
        self::assertSame(['pa_artifact', 'pa_artifact_revision'], Schema::tableNames());
        self::assertCount(2, Schema::createSql());

        foreach (Schema::createSql() as $sql) {
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS', $sql);
            self::assertStringContainsString('ENGINE=InnoDB', $sql);
            self::assertStringNotContainsString('CASCADE', $sql);
        }
    }

    public function testSchemaDeclaresTenantIdentityLineageAndImmutableEnvelopeConstraints(): void
    {
        $artifact = Schema::createTableSql('pa_artifact');
        self::assertStringContainsString('UNIQUE KEY `uk_artifact_identity` (`tenant_id`, `artifact_type`, `artifact_key`)', $artifact);
        self::assertStringContainsString('`revision` BIGINT UNSIGNED NOT NULL DEFAULT 1', $artifact);
        self::assertStringContainsString('`next_revision_number` BIGINT UNSIGNED NOT NULL DEFAULT 1', $artifact);
        self::assertStringContainsString('FOREIGN KEY (`tenant_id`, `created_by_member_id`)', $artifact);
        self::assertStringContainsString('ON DELETE RESTRICT', $artifact);

        $revision = Schema::createTableSql('pa_artifact_revision');
        self::assertStringContainsString('UNIQUE KEY `uk_artifact_revision_number` (`tenant_id`, `artifact_id`, `revision_number`)', $revision);
        self::assertStringContainsString('FOREIGN KEY (`tenant_id`, `artifact_id`, `parent_revision_id`)', $revision);
        self::assertStringContainsString("`state` IN ('pending', 'finalized')", $revision);
        self::assertStringContainsString('`canonical_envelope_json` JSON NULL', $revision);
        self::assertStringContainsString('`canonical_envelope_sha256` CHAR(64)', $revision);
        self::assertStringContainsString('`state` = \'pending\'', $revision);
        self::assertStringContainsString('`state` = \'finalized\'', $revision);
    }

    public function testUnknownTableFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Schema::createTableSql('pa_product_payload');
    }
}
