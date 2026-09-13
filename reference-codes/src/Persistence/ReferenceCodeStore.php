<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\ReferenceCodes\Application\ReferenceCodeException;
use PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetDefinition;
use PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetRegistry;
use think\db\exception\PDOException;
use think\db\PDOConnection;
use think\db\Query;

final readonly class ReferenceCodeStore
{
    public function __construct(private PDOConnection $connection) {}

    /** @template T
     * @param callable(): T $operation
     * @return T
     */
    public function atomically(callable $operation): mixed
    {
        return $this->connection->transaction($operation);
    }

    /** @return array{inserted: int, updated: int, retired: int, reactivated: int} */
    public function synchronize(ReferenceCodeSetRegistry $registry, DateTimeImmutable $now): array
    {
        $this->assertExactMillisecond($now);

        return $this->connection->transaction(function () use ($registry, $now): array {
            $rows = $this->connection->query('SELECT * FROM pa_reference_code_set FOR UPDATE');
            $existing = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $existing[(string) $row['module_key'] . ':' . (string) $row['set_key']] = $row;
                }
            }
            $declared = [];
            $counts = ['inserted' => 0, 'updated' => 0, 'retired' => 0, 'reactivated' => 0];
            foreach ($registry->all() as $definition) {
                $qualifiedKey = $definition->qualifiedKey();
                $declared[$qualifiedKey] = true;
                $row = $existing[$qualifiedKey] ?? null;
                if ($row === null) {
                    $this->insertDefinition($definition, $now);
                    ++$counts['inserted'];
                    continue;
                }
                $reactivating = (string) $row['lifecycle'] === 'retired';
                if (!$reactivating && hash_equals((string) $row['definition_digest'], $definition->digest)) {
                    continue;
                }
                $this->connection->execute(<<<'SQL'
UPDATE pa_reference_code_set
SET name = :name, description = :description, definition_digest = :definition_digest,
    lifecycle = 'active', revision = revision + 1, updated_at = :updated_at
WHERE id = :id
SQL, [
                    'name' => $definition->name,
                    'description' => $definition->description,
                    'definition_digest' => $definition->digest,
                    'updated_at' => $this->date($now),
                    'id' => (int) $row['id'],
                ]);
                ++$counts[$reactivating ? 'reactivated' : 'updated'];
            }
            foreach ($existing as $qualifiedKey => $row) {
                if (isset($declared[$qualifiedKey]) || (string) $row['lifecycle'] === 'retired') {
                    continue;
                }
                $affected = $this->connection->execute(<<<'SQL'
UPDATE pa_reference_code_set
SET lifecycle = 'retired', revision = revision + 1, updated_at = :updated_at
WHERE id = :id AND lifecycle = 'active'
SQL, ['updated_at' => $this->date($now), 'id' => (int) $row['id']]);
                $counts['retired'] += $affected;
            }

            return $counts;
        });
    }

    public function assertCurrentDefinition(ReferenceCodeSetDefinition $definition, bool $forShare = false): void
    {
        $this->definitionRow($definition, $forShare);
    }

    public function create(
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        string $code,
        string $label,
        string $metadataJson,
        string $status,
        int $sortOrder,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
    ): DateTimeImmutable {
        return $this->connection->transaction(function () use (
            $definition,
            $context,
            $code,
            $label,
            $metadataJson,
            $status,
            $sortOrder,
            $effectiveAt,
            $expiresAt,
        ): DateTimeImmutable {
            $this->assertTenantActor($context);
            $set = $this->definitionRow($definition, true);
            $existing = $this->entry((int) $set['id'], $context->tenantId, $code, true);
            if ($existing !== null) {
                throw (string) $existing['lifecycle'] === 'retired'
                    ? ReferenceCodeException::retired()
                    : ReferenceCodeException::alreadyExists();
            }
            $now = $this->databaseNow();
            try {
                $entryId = (int) (new Query($this->connection))
                    ->table('pa_reference_code_entry')
                    ->insertGetId([
                    'tenant_id' => $context->tenantId,
                    'set_id' => (int) $set['id'],
                    'code' => $code,
                    'lifecycle' => 'active',
                    'revision' => 1,
                    'created_by_member_id' => $context->memberId,
                    'updated_by_member_id' => $context->memberId,
                    'retired_at' => null,
                    'created_at' => $this->date($now),
                    'updated_at' => $this->date($now),
                ]);
                $this->insertVersion(
                    $entryId,
                    1,
                    $label,
                    $metadataJson,
                    $status,
                    $sortOrder,
                    $effectiveAt,
                    $expiresAt,
                    $context->memberId,
                    $now,
                );
            } catch (PDOException $exception) {
                if ($this->isCreateCompetition($exception)) {
                    throw ReferenceCodeException::alreadyExists();
                }
                throw $exception;
            }

            return $now;
        });
    }

    public function replace(
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        string $code,
        string $label,
        string $metadataJson,
        string $status,
        int $sortOrder,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        int $expectedRevision,
    ): DateTimeImmutable {
        return $this->connection->transaction(function () use (
            $definition,
            $context,
            $code,
            $label,
            $metadataJson,
            $status,
            $sortOrder,
            $effectiveAt,
            $expiresAt,
            $expectedRevision,
        ): DateTimeImmutable {
            $this->assertTenantActor($context);
            $set = $this->definitionRow($definition, true);
            $entry = $this->entry((int) $set['id'], $context->tenantId, $code, true);
            if ($entry === null) {
                throw ReferenceCodeException::codeNotFound();
            }
            if ((string) $entry['lifecycle'] === 'retired') {
                throw ReferenceCodeException::retired();
            }
            if ((int) $entry['revision'] !== $expectedRevision) {
                throw ReferenceCodeException::revisionMismatch();
            }
            $revision = $expectedRevision + 1;
            $now = $this->databaseNow();
            $affected = $this->connection->execute(<<<'SQL'
UPDATE pa_reference_code_entry
SET revision = :revision, updated_by_member_id = :member_id, updated_at = :updated_at
WHERE id = :id AND lifecycle = 'active' AND revision = :expected_revision
SQL, [
                'revision' => $revision,
                'member_id' => $context->memberId,
                'updated_at' => $this->date($now),
                'id' => (int) $entry['id'],
                'expected_revision' => $expectedRevision,
            ]);
            if ($affected !== 1) {
                throw ReferenceCodeException::revisionMismatch();
            }
            $this->insertVersion(
                (int) $entry['id'],
                $revision,
                $label,
                $metadataJson,
                $status,
                $sortOrder,
                $effectiveAt,
                $expiresAt,
                $context->memberId,
                $now,
            );

            return $now;
        });
    }

    public function retire(
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        string $code,
        int $expectedRevision,
    ): DateTimeImmutable {
        return $this->connection->transaction(function () use ($definition, $context, $code, $expectedRevision): DateTimeImmutable {
            $this->assertTenantActor($context);
            $set = $this->definitionRow($definition, true);
            $entry = $this->entry((int) $set['id'], $context->tenantId, $code, true);
            if ($entry === null) {
                throw ReferenceCodeException::codeNotFound();
            }
            if ((string) $entry['lifecycle'] === 'retired') {
                throw ReferenceCodeException::retired();
            }
            if ((int) $entry['revision'] !== $expectedRevision) {
                throw ReferenceCodeException::revisionMismatch();
            }
            $last = $this->fetchOne(<<<'SQL'
SELECT * FROM pa_reference_code_entry_version
WHERE entry_id = :entry_id AND revision = :revision
FOR UPDATE
SQL, ['entry_id' => (int) $entry['id'], 'revision' => $expectedRevision]);
            if ($last === null) {
                throw ReferenceCodeException::internal();
            }
            $revision = $expectedRevision + 1;
            $now = $this->databaseNow();
            $affected = $this->connection->execute(<<<'SQL'
UPDATE pa_reference_code_entry
SET lifecycle = 'retired', revision = :revision, updated_by_member_id = :member_id,
    retired_at = :retired_at, updated_at = :updated_at
WHERE id = :id AND lifecycle = 'active' AND revision = :expected_revision
SQL, [
                'revision' => $revision,
                'member_id' => $context->memberId,
                'retired_at' => $this->date($now),
                'updated_at' => $this->date($now),
                'id' => (int) $entry['id'],
                'expected_revision' => $expectedRevision,
            ]);
            if ($affected !== 1) {
                throw ReferenceCodeException::revisionMismatch();
            }
            $this->insertVersion(
                (int) $entry['id'],
                $revision,
                (string) $last['label'],
                (string) $last['metadata_json'],
                'inactive',
                (int) $last['sort_order'],
                $now,
                null,
                $context->memberId,
                $now,
            );

            return $now;
        });
    }

    /**
     * @return array{
     *   as_of: DateTimeImmutable,
     *   entries: list<array{entry: array<string, mixed>, versions: list<array<string, mixed>>}>
     * }
     */
    public function snapshot(
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        ?string $code,
        ?DateTimeImmutable $asOf,
    ): array {
        return $this->connection->transaction(function () use ($definition, $context, $code, $asOf): array {
            $this->assertTenantActor($context);
            $set = $this->definitionRow($definition);
            $comparisonTime = $asOf ?? $this->databaseNow();
            $this->assertExactMillisecond($comparisonTime);
            $sql = <<<'SQL'
SELECT e.* FROM pa_reference_code_entry e
WHERE e.tenant_id = :tenant_id AND e.set_id = :set_id
SQL;
            $parameters = ['tenant_id' => $context->tenantId, 'set_id' => (int) $set['id']];
            if ($code !== null) {
                $sql .= ' AND e.code = :code';
                $parameters['code'] = $code;
            }
            $sql .= ' ORDER BY BINARY e.code ASC';
            $rows = $this->connection->query($sql, $parameters);
            $entries = [];
            foreach ($rows as $entry) {
                if (!is_array($entry)) {
                    throw ReferenceCodeException::internal();
                }
                if (!$this->memberBelongsToTenant($context->tenantId, $entry['created_by_member_id'] ?? null)
                    || !$this->memberBelongsToTenant($context->tenantId, $entry['updated_by_member_id'] ?? null)) {
                    throw ReferenceCodeException::internal();
                }
                $versions = $this->connection->query(<<<'SQL'
SELECT * FROM pa_reference_code_entry_version
WHERE entry_id = :entry_id
ORDER BY revision ASC
SQL, ['entry_id' => (int) $entry['id']]);
                foreach ($versions as $version) {
                    if (!is_array($version)
                        || !$this->memberBelongsToTenant($context->tenantId, $version['changed_by_member_id'] ?? null)) {
                        throw ReferenceCodeException::internal();
                    }
                }
                $entries[] = ['entry' => $entry, 'versions' => array_values($versions)];
            }

            return ['as_of' => $comparisonTime, 'entries' => $entries];
        });
    }

    /** @return list<array{module_key: string, set_key: string, name: string, description: string, definition_revision: int}> */
    public function definitionSummaries(ReferenceCodeSetRegistry $registry): array
    {
        return $this->connection->transaction(function () use ($registry): array {
            $summaries = [];
            foreach ($registry->all() as $definition) {
                $row = $this->definitionRow($definition);
                $summaries[] = [
                    'module_key' => $definition->moduleKey,
                    'set_key' => $definition->key,
                    'name' => $definition->name,
                    'description' => $definition->description,
                    'definition_revision' => (int) $row['revision'],
                ];
            }

            return $summaries;
        });
    }

    private function insertDefinition(ReferenceCodeSetDefinition $definition, DateTimeImmutable $now): void
    {
        $this->connection->execute(<<<'SQL'
INSERT INTO pa_reference_code_set (
  module_key, set_key, name, description, definition_digest,
  lifecycle, revision, created_at, updated_at
) VALUES (
  :module_key, :set_key, :name, :description, :definition_digest,
  'active', 1, :created_at, :updated_at
)
SQL, [
            'module_key' => $definition->moduleKey,
            'set_key' => $definition->key,
            'name' => $definition->name,
            'description' => $definition->description,
            'definition_digest' => $definition->digest,
            'created_at' => $this->date($now),
            'updated_at' => $this->date($now),
        ]);
    }

    /** @return array<string, mixed> */
    private function definitionRow(ReferenceCodeSetDefinition $definition, bool $forShare = false): array
    {
        $row = $this->fetchOne(<<<'SQL'
SELECT * FROM pa_reference_code_set
WHERE module_key = :module_key AND set_key = :set_key AND lifecycle = 'active'
SQL . ($forShare ? ' FOR SHARE' : ''), [
            'module_key' => $definition->moduleKey,
            'set_key' => $definition->key,
        ]);
        if ($row === null || !hash_equals((string) $row['definition_digest'], $definition->digest)) {
            throw ReferenceCodeException::setNotFound();
        }
        if ((int) $row['id'] < 1
            || (int) $row['revision'] < 1
            || (string) $row['module_key'] !== $definition->moduleKey
            || (string) $row['set_key'] !== $definition->key
            || (string) $row['name'] !== $definition->name
            || (string) $row['description'] !== $definition->description) {
            throw ReferenceCodeException::internal();
        }

        return $row;
    }

    private function assertTenantActor(TenantContext $context): void
    {
        if ($context->tenantId < 1
            || !$this->memberBelongsToTenant($context->tenantId, $context->memberId)) {
            throw ReferenceCodeException::codeNotFound();
        }
    }

    private function memberBelongsToTenant(int $tenantId, mixed $memberId): bool
    {
        if ($tenantId < 1
            || (!is_int($memberId) && !is_string($memberId))
            || preg_match('/^[1-9][0-9]*$/D', (string) $memberId) !== 1) {
            return false;
        }

        return $this->fetchOne(<<<'SQL'
SELECT id FROM pa_tenant_member
WHERE tenant_id = :tenant_id AND id = :member_id
SQL, ['tenant_id' => $tenantId, 'member_id' => $memberId]) !== null;
    }

    /** @return array<string, mixed>|null */
    private function entry(int $setId, int $tenantId, string $code, bool $forUpdate): ?array
    {
        return $this->fetchOne(<<<'SQL'
SELECT * FROM pa_reference_code_entry
WHERE tenant_id = :tenant_id AND set_id = :set_id AND code = :code
SQL . ($forUpdate ? ' FOR UPDATE' : ''), [
            'tenant_id' => $tenantId,
            'set_id' => $setId,
            'code' => $code,
        ]);
    }

    private function insertVersion(
        int $entryId,
        int $revision,
        string $label,
        string $metadataJson,
        string $status,
        int $sortOrder,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        int $memberId,
        DateTimeImmutable $createdAt,
    ): void {
        $this->connection->execute(<<<'SQL'
INSERT INTO pa_reference_code_entry_version (
  entry_id, revision, label, metadata_json, status, sort_order,
  effective_at, expires_at, changed_by_member_id, created_at
) VALUES (
  :entry_id, :revision, :label, :metadata_json, :status, :sort_order,
  :effective_at, :expires_at, :changed_by_member_id, :created_at
)
SQL, [
            'entry_id' => $entryId,
            'revision' => $revision,
            'label' => $label,
            'metadata_json' => $metadataJson,
            'status' => $status,
            'sort_order' => $sortOrder,
            'effective_at' => $this->date($effectiveAt),
            'expires_at' => $expiresAt === null ? null : $this->date($expiresAt),
            'changed_by_member_id' => $memberId,
            'created_at' => $this->date($createdAt),
        ]);
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $parameters): ?array
    {
        $row = $this->connection->query($sql, $parameters)[0] ?? null;

        return is_array($row) ? $row : null;
    }

    private function databaseNow(): DateTimeImmutable
    {
        $row = $this->connection->query('SELECT UTC_TIMESTAMP(3) AS database_time')[0] ?? null;
        $value = is_array($row) ? ($row['database_time'] ?? null) : null;
        if (!is_string($value)) {
            throw ReferenceCodeException::internal();
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function assertExactMillisecond(DateTimeImmutable $date): void
    {
        if (((int) $date->format('u')) % 1000 !== 0) {
            throw ReferenceCodeException::invalid(
                'REFERENCE_CODE_INTERVAL_INVALID',
                'Reference-code timestamps require exact millisecond precision.',
            );
        }
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function isCreateCompetition(PDOException $exception): bool
    {
        $error = $exception->getData()['PDO Error Info'] ?? [];
        $sqlState = (string) ($error['SQLSTATE'] ?? $exception->getCode());
        $driverCode = (int) ($error['Driver Error Code'] ?? 0);

        return ($sqlState === '23000' && $driverCode === 1062)
            || ($sqlState === '40001' && $driverCode === 1213)
            || ($sqlState === 'HY000' && $driverCode === 1205);
    }
}
