<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\ReferenceCodes\Application\ReferenceCodeException;
use PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetDefinition;
use PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetRegistry;
use PeanutAdmin\ReferenceCodes\Persistence\Model\ReferenceCodeEntryRecord;
use think\db\exception\PDOException;
use think\facade\Db;

final class ReferenceCodeStore
{
    /** @template T
     * @param callable(): T $operation
     * @return T
     */
    public function atomically(callable $operation): mixed
    {
        return Db::transaction($operation);
    }

    /** @return array{inserted: int, updated: int, retired: int, reactivated: int} */
    public function synchronize(ReferenceCodeSetRegistry $registry, DateTimeImmutable $now): array
    {
        $this->assertExactMillisecond($now);

        return Db::transaction(function () use ($registry, $now): array {
            $rows = Db::name('reference_code_set')->lock(true)->select()->toArray();
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
                Db::name('reference_code_set')->where('id', (int) $row['id'])->update([
                    'name' => $definition->name,
                    'description' => $definition->description,
                    'definition_digest' => $definition->digest,
                    'lifecycle' => 'active',
                    'revision' => Db::raw('revision + 1'),
                    'updated_at' => $this->date($now),
                ]);
                ++$counts[$reactivating ? 'reactivated' : 'updated'];
            }
            foreach ($existing as $qualifiedKey => $row) {
                if (isset($declared[$qualifiedKey]) || (string) $row['lifecycle'] === 'retired') {
                    continue;
                }
                $affected = Db::name('reference_code_set')->where('id', (int) $row['id'])
                    ->where('lifecycle', 'active')->update([
                        'lifecycle' => 'retired',
                        'revision' => Db::raw('revision + 1'),
                        'updated_at' => $this->date($now),
                    ]);
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
        return Db::transaction(function () use (
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
                $entryId = (int) Db::name('reference_code_entry')->insertGetId([
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
        return Db::transaction(function () use (
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
            $affected = Db::name('reference_code_entry')->where('id', (int) $entry['id'])
                ->where('lifecycle', 'active')->where('revision', $expectedRevision)->update([
                'revision' => $revision,
                'updated_by_member_id' => $context->memberId,
                'updated_at' => $this->date($now),
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
        return Db::transaction(function () use ($definition, $context, $code, $expectedRevision): DateTimeImmutable {
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
            $last = Db::name('reference_code_entry_version')->where('entry_id', (int) $entry['id'])
                ->where('revision', $expectedRevision)->lock(true)->find();
            if ($last === null) {
                throw ReferenceCodeException::internal();
            }
            $revision = $expectedRevision + 1;
            $now = $this->databaseNow();
            $affected = Db::name('reference_code_entry')->where('id', (int) $entry['id'])
                ->where('lifecycle', 'active')->where('revision', $expectedRevision)->update([
                'lifecycle' => 'retired',
                'revision' => $revision,
                'updated_by_member_id' => $context->memberId,
                'retired_at' => $this->date($now),
                'updated_at' => $this->date($now),
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
        return Db::transaction(function () use ($definition, $context, $code, $asOf): array {
            $this->assertTenantActor($context);
            $set = $this->definitionRow($definition);
            $comparisonTime = $asOf ?? $this->databaseNow();
            $this->assertExactMillisecond($comparisonTime);
            $query = ReferenceCodeEntryRecord::where('tenant_id', $context->tenantId)
                ->where('set_id', (int) $set['id']);
            if ($code !== null) {
                $query->where('code', $code);
            }
            $rows = $query->orderRaw('BINARY `code` ASC')->select()->toArray();
            $entries = [];
            foreach ($rows as $entry) {
                if (!is_array($entry)) {
                    throw ReferenceCodeException::internal();
                }
                if (!$this->memberBelongsToTenant($context->tenantId, $entry['created_by_member_id'] ?? null)
                    || !$this->memberBelongsToTenant($context->tenantId, $entry['updated_by_member_id'] ?? null)) {
                    throw ReferenceCodeException::internal();
                }
                $versions = Db::name('reference_code_entry_version')->where('entry_id', (int) $entry['id'])
                    ->order('revision')->select()->toArray();
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
        return Db::transaction(function () use ($registry): array {
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
        Db::name('reference_code_set')->insert([
            'module_key' => $definition->moduleKey,
            'set_key' => $definition->key,
            'name' => $definition->name,
            'description' => $definition->description,
            'definition_digest' => $definition->digest,
            'lifecycle' => 'active',
            'revision' => 1,
            'created_at' => $this->date($now),
            'updated_at' => $this->date($now),
        ]);
    }

    /** @return array<string, mixed> */
    private function definitionRow(ReferenceCodeSetDefinition $definition, bool $forShare = false): array
    {
        $query = Db::name('reference_code_set')->where('module_key', $definition->moduleKey)
            ->where('set_key', $definition->key)->where('lifecycle', 'active');
        if ($forShare) {
            $query->lock('FOR SHARE');
        }
        $row = $query->find();
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

        return Db::name('tenant_member')->where('tenant_id', $tenantId)
            ->where('id', $memberId)->value('id') !== null;
    }

    /** @return array<string, mixed>|null */
    private function entry(int $setId, int $tenantId, string $code, bool $forUpdate): ?array
    {
        $query = Db::name('reference_code_entry')->where('tenant_id', $tenantId)
            ->where('set_id', $setId)->where('code', $code);
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->find();

        return is_array($row) ? $row : null;
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
        Db::name('reference_code_entry_version')->insert([
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

    private function databaseNow(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.v',
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
            new DateTimeZone('UTC'),
        ) ?: throw ReferenceCodeException::internal();
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
