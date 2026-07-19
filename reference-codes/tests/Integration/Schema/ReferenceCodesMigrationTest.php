<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Tests\Integration\Schema;

use PDO;
use PeanutAdmin\ReferenceCodes\Database\Schema;
use PeanutAdmin\ReferenceCodes\Tests\Integration\Support\ReferenceCodesDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/ReferenceCodesDatabaseTestCase.php';

final class ReferenceCodesMigrationTest extends ReferenceCodesDatabaseTestCase
{
    public function testCreatesExactlyThreeTablesIdempotentlyAndDropsOnlyOwnedTables(): void
    {
        $this->runner->migrate();
        self::assertSame([
            'pa_reference_code_set',
            'pa_reference_code_entry',
            'pa_reference_code_entry_version',
        ], Schema::tableNames());
        self::assertSame(3, (int) $this->scalar(<<<'SQL'
SELECT COUNT(*) FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name LIKE 'pa_reference_code_%'
SQL));
        $this->runner->rollbackAll();
        self::assertSame(0, (int) $this->scalar(<<<'SQL'
SELECT COUNT(*) FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name LIKE 'pa_reference_code_%'
SQL));
        self::assertSame(1, (int) $this->scalar(<<<'SQL'
SELECT COUNT(*) FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'pa_tenant'
SQL));
    }

    public function testSetTableHasExactColumns(): void
    {
        self::assertSame([
            'id', 'module_key', 'set_key', 'name', 'description', 'definition_digest',
            'lifecycle', 'revision', 'created_at', 'updated_at',
        ], $this->columns('pa_reference_code_set'));
    }

    public function testEntryTableHasExactColumns(): void
    {
        self::assertSame([
            'id', 'tenant_id', 'set_id', 'code', 'lifecycle', 'revision',
            'created_by_member_id', 'updated_by_member_id', 'retired_at',
            'created_at', 'updated_at',
        ], $this->columns('pa_reference_code_entry'));
    }

    public function testVersionTableHasExactColumns(): void
    {
        self::assertSame([
            'id', 'entry_id', 'revision', 'label', 'metadata_json', 'status',
            'sort_order', 'effective_at', 'expires_at', 'changed_by_member_id', 'created_at',
        ], $this->columns('pa_reference_code_entry_version'));
    }

    public function testUniqueAndLookupIndexesMatchContract(): void
    {
        self::assertSame([
            'PRIMARY:id',
            'idx_reference_code_set_lifecycle:lifecycle,module_key,set_key',
            'uk_reference_code_set:module_key,set_key',
        ], $this->indexes('pa_reference_code_set'));
        self::assertContains(
            'uk_reference_code_entry:tenant_id,set_id,code',
            $this->indexes('pa_reference_code_entry'),
        );
        self::assertContains(
            'uk_reference_code_entry_version:entry_id,revision',
            $this->indexes('pa_reference_code_entry_version'),
        );
    }

    public function testEveryForeignKeyUsesRestrict(): void
    {
        $statement = $this->database->query(<<<'SQL'
SELECT delete_rule FROM information_schema.referential_constraints
WHERE constraint_schema = DATABASE() AND table_name LIKE 'pa_reference_code_%'
ORDER BY constraint_name
SQL);
        self::assertNotFalse($statement);
        $rules = array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
        self::assertCount(6, $rules);
        self::assertSame(array_fill(0, 6, 'RESTRICT'), $rules);
    }

    public function testChecksRejectInvalidLifecycleRevisionAndRetirementShape(): void
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);
        $tenant = $this->tenant('schema-a');
        $setId = (int) $this->scalar('SELECT id FROM pa_reference_code_set');
        $this->assertDatabaseRejects(fn() => $this->insertEntry([
            'tenant_id' => $tenant['tenant_id'], 'set_id' => $setId, 'code' => 'bad-lifecycle',
            'lifecycle' => 'invalid', 'revision' => 1,
            'created_by_member_id' => $tenant['member_id'], 'updated_by_member_id' => $tenant['member_id'],
        ]));
        $this->assertDatabaseRejects(fn() => $this->insertEntry([
            'tenant_id' => $tenant['tenant_id'], 'set_id' => $setId, 'code' => 'bad-revision',
            'lifecycle' => 'active', 'revision' => 0,
            'created_by_member_id' => $tenant['member_id'], 'updated_by_member_id' => $tenant['member_id'],
        ]));
        $this->assertDatabaseRejects(fn() => $this->insertEntry([
            'tenant_id' => $tenant['tenant_id'], 'set_id' => $setId, 'code' => 'bad-retired-at',
            'lifecycle' => 'active', 'revision' => 1, 'retired_at' => self::NOW,
            'created_by_member_id' => $tenant['member_id'], 'updated_by_member_id' => $tenant['member_id'],
        ]));
        self::assertInstanceOf(\PeanutAdmin\ReferenceCodes\Persistence\PdoReferenceCodeRepository::class, $repository);
    }

    public function testChecksRejectInvalidStatusSortIntervalAndForeignKeys(): void
    {
        $definition = $this->definition();
        $repository = $this->repository($definition);
        $tenant = $this->tenant('schema-b');
        $entry = $this->create($this->adminService($repository), $definition, $tenant['context']);
        $entryId = (int) $this->scalar('SELECT id FROM pa_reference_code_entry');
        foreach ([
            ['revision' => 2, 'status' => 'invalid'],
            ['revision' => 2, 'sort_order' => 1000001],
            ['revision' => 2, 'effective_at' => '2026-07-20 02:00:00.000', 'expires_at' => '2026-07-20 02:00:00.000'],
            ['revision' => 2, 'changed_by_member_id' => 999999],
            ['entry_id' => 999999, 'revision' => 2],
        ] as $override) {
            $this->assertDatabaseRejects(fn() => $this->insertVersion($entryId, $tenant['member_id'], $override));
        }
        self::assertSame('sample-code', $entry->code);
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        $statement = $this->database->prepare(<<<'SQL'
SELECT column_name FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = :table_name
ORDER BY ordinal_position
SQL);
        $statement->execute(['table_name' => $table]);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<string> */
    private function indexes(string $table): array
    {
        $statement = $this->database->prepare(<<<'SQL'
SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = :table_name
GROUP BY index_name
ORDER BY index_name
SQL);
        $statement->execute(['table_name' => $table]);

        $indexes = array_map(
            static fn(array $row): string => implode(':', array_map('strval', $row)),
            $statement->fetchAll(PDO::FETCH_NUM),
        );
        sort($indexes, SORT_STRING);

        return $indexes;
    }

    /** @param array<string, mixed> $values */
    private function insertEntry(array $values): void
    {
        $input = array_merge(['retired_at' => null, 'created_at' => '2026-07-20 00:00:00.000', 'updated_at' => '2026-07-20 00:00:00.000'], $values);
        $statement = $this->database->prepare(<<<'SQL'
INSERT INTO pa_reference_code_entry (
  tenant_id, set_id, code, lifecycle, revision, created_by_member_id,
  updated_by_member_id, retired_at, created_at, updated_at
) VALUES (
  :tenant_id, :set_id, :code, :lifecycle, :revision, :created_by_member_id,
  :updated_by_member_id, :retired_at, :created_at, :updated_at
)
SQL);
        $statement->execute($input);
    }

    /** @param array<string, mixed> $override */
    private function insertVersion(int $entryId, int $memberId, array $override): void
    {
        $input = array_merge([
            'entry_id' => $entryId,
            'revision' => 2,
            'label' => 'Invalid fixture',
            'metadata_json' => '{}',
            'status' => 'active',
            'sort_order' => 0,
            'effective_at' => '2026-07-20 01:00:00.000',
            'expires_at' => null,
            'changed_by_member_id' => $memberId,
            'created_at' => '2026-07-20 01:00:00.000',
        ], $override);
        $statement = $this->database->prepare(<<<'SQL'
INSERT INTO pa_reference_code_entry_version (
  entry_id, revision, label, metadata_json, status, sort_order,
  effective_at, expires_at, changed_by_member_id, created_at
) VALUES (
  :entry_id, :revision, :label, :metadata_json, :status, :sort_order,
  :effective_at, :expires_at, :changed_by_member_id, :created_at
)
SQL);
        $statement->execute($input);
    }
}
