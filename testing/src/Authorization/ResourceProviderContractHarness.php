<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Authorization;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\DataPermission\Constraint\ThinkPhpQueryConstraintApplier;
use PeanutAdmin\DataPermission\Engine\DataPermissionEngine;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\db\PDOConnection;
use think\db\Query;
use think\facade\Db;

final readonly class ResourceProviderContractHarness
{
    public function __construct(
        private DataPermissionEngine $engine,
        private TenantContext $context,
        private AuthorizationSqlTrace $trace,
        private string $resourceKey = 'fixture.record',
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(
        string $operation = 'list',
        ?TypedResourceTargetCollection $targets = null,
        ?string $namePrefix = null,
    ): array {
        $query = $this->authorizedQuery($operation, $targets, $namePrefix)->order('record.id');
        $this->traceQuery($query);

        return array_values($query->select()->toArray());
    }

    /** @return array<string, mixed>|null */
    public function detail(
        int $recordId,
        string $operation,
        TypedResourceTargetCollection $targets,
    ): ?array {
        $query = $this->authorizedQuery($operation, $targets)->where('record.id', $recordId);
        $this->traceQuery($query);
        $row = $query->find();

        return is_array($row) ? $row : null;
    }

    /** @param array{name: string, tenant_id?: int|string|null} $payload */
    public function create(
        string $operation,
        TypedResourceTargetCollection $targets,
        array $payload,
    ): int {
        $decision = $this->engine->decideCreate(
            $this->context,
            $this->resourceKey,
            $operation,
            $targets,
        );
        if (!$decision->allowed) {
            throw new DataAuthorizationException($decision->reasonCode, 'Create target is denied.');
        }
        $projectId = $this->singlePrimaryTarget($targets);
        return (int) $this->query('fixture_record')->insertGetId([
            'tenant_id' => $this->context->tenantId,
            'project_id' => $projectId,
            'created_by_member_id' => $this->context->memberId,
            'name' => $payload['name'],
        ]);
    }

    public function update(
        int $recordId,
        string $operation,
        TypedResourceTargetCollection $targets,
        string $name,
    ): bool {
        return $this->transaction(function () use ($recordId, $operation, $targets, $name): bool {
            if ($this->detail($recordId, $operation, $targets) === null) {
                throw new DataAuthorizationException('AUTHZ_DATA_DENIED', 'Record not found.');
            }
            return $this->query('fixture_record')
                ->where('tenant_id', $this->context->tenantId)
                ->where('id', $recordId)
                ->update(['name' => $name]) === 1;
        });
    }

    public function delete(
        int $recordId,
        string $operation,
        TypedResourceTargetCollection $targets,
    ): bool {
        return $this->transaction(function () use ($recordId, $operation, $targets): bool {
            if ($this->detail($recordId, $operation, $targets) === null) {
                throw new DataAuthorizationException('AUTHZ_DATA_DENIED', 'Record not found.');
            }
            return $this->query('fixture_record')
                ->where('tenant_id', $this->context->tenantId)
                ->where('id', $recordId)
                ->delete() === 1;
        });
    }

    /** @param non-empty-list<int> $recordIds */
    public function batchUpdate(
        array $recordIds,
        string $operation,
        TypedResourceTargetCollection $targets,
        string $name,
    ): int {
        return $this->transaction(function () use ($recordIds, $operation, $targets, $name): int {
            $projectId = $this->singlePrimaryTarget($targets);
            $query = $this->authorizedQuery($operation, $targets)
                ->whereIn('record.id', $recordIds)
                ->where('record.project_id', $projectId)
                ->lock(true);
            $this->traceQuery($query);
            $authorizedIds = array_map('intval', $query->column('record.id'));
            if (count(array_unique($authorizedIds)) !== count(array_unique($recordIds))) {
                throw new DataAuthorizationException('AUTHZ_DATA_DENIED', 'The batch contains a denied record.');
            }

            $updated = $this->query('fixture_record')
                ->where('tenant_id', $this->context->tenantId)
                ->where('project_id', $projectId)
                ->whereIn('id', $recordIds)
                ->update(['name' => $name]);
            $this->recordMultiTargetAudit($operation, $recordIds);

            return $updated;
        });
    }

    /**
     * @param list<array{name: string, project_id: string, tenant_id?: int|string|null}> $rows
     */
    public function import(string $operation, array $rows): int
    {
        return $this->transaction(function () use ($operation, $rows): int {
            foreach ($rows as $row) {
                $targets = self::targets('fixture.project', [$row['project_id']]);
                $decision = $this->engine->decideCreate(
                    $this->context,
                    $this->resourceKey,
                    $operation,
                    $targets,
                );
                if (!$decision->allowed) {
                    throw new DataAuthorizationException($decision->reasonCode, 'Import row is denied.');
                }
            }
            $payload = [];
            foreach ($rows as $row) {
                $payload[] = [
                    'tenant_id' => $this->context->tenantId,
                    'project_id' => $row['project_id'],
                    'created_by_member_id' => $this->context->memberId,
                    'name' => $row['name'],
                ];
            }
            $this->query('fixture_record')->insertAll($payload);

            return count($rows);
        });
    }

    public function exportContract(
        string $operation,
        ?TypedResourceTargetCollection $targets = null,
    ): RevalidatingReadContract {
        return new RevalidatingReadContract(
            fn(): array => $this->list($operation, $targets),
        );
    }

    public function jobContract(
        string $operation,
        ?TypedResourceTargetCollection $targets = null,
    ): RevalidatingReadContract {
        return new RevalidatingReadContract(
            fn(): array => $this->list($operation, $targets),
        );
    }

    /** @param list<string> $ids */
    public static function targets(string $resourceKey, array $ids): TypedResourceTargetCollection
    {
        return new TypedResourceTargetCollection([
            new TypedResourceTargetSet($resourceKey, $ids),
        ]);
    }

    /**
     * @return Query
     */
    private function authorizedQuery(
        string $operation,
        ?TypedResourceTargetCollection $targets,
        ?string $namePrefix = null,
    ): Query {
        $query = $this->query('fixture_record')->alias('record');
        (new ThinkPhpQueryConstraintApplier())->apply($query, $this->engine->queryConstraint(
            $this->context,
            $this->resourceKey,
            $operation,
            $targets ?? new TypedResourceTargetCollection(),
        ));
        if ($namePrefix !== null) {
            $query->whereLike('record.name', $namePrefix . '%');
        }

        return $query;
    }

    private function singlePrimaryTarget(TypedResourceTargetCollection $targets): string
    {
        foreach ($targets->sets as $set) {
            if ($set->targetRole === 'primary' && count($set->targetIds) === 1) {
                return $set->targetIds[0];
            }
        }

        throw new DataAuthorizationException(
            'AUTHZ_TARGET_CARDINALITY_INVALID',
            'The contract requires one primary target.',
        );
    }

    /** @param non-empty-list<int> $targetIds */
    private function recordMultiTargetAudit(string $action, array $targetIds): void
    {
        $normalized = array_map('strval', $targetIds);
        sort($normalized, SORT_STRING);
        $this->query('pa_tenant_audit_event')->insert([
            'tenant_id' => $this->context->tenantId,
            'event_type' => 'fixture.batch.updated',
            'action' => $action,
            'outcome' => 'success',
            'actor_tenant_id' => $this->context->tenantId,
            'actor_tenant_member_id' => $this->context->memberId,
            'actor_account_id' => $this->context->accountId,
            'actor_type' => 'member',
            'target_resource_type' => 'fixture.record',
            'target_count' => count($normalized),
            'target_set_digest' => hash('sha256', implode('|', $normalized)),
            'authorization_basis_json' => json_encode(['audience' => 'tenant'], JSON_THROW_ON_ERROR),
            'request_id' => $this->context->requestId,
            'metadata_json' => json_encode(['target_ids_recorded' => false], JSON_THROW_ON_ERROR),
            'occurred_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
        ]);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation): mixed
    {
        return Db::transaction($operation);
    }

    private function traceQuery(Query $query): void
    {
        $sql = (clone $query)->fetchSql()->select();
        $this->trace->record(is_string($sql) ? $sql : '', []);
    }

    private function query(string $table): Query
    {
        $connection = Db::connect();
        if (!$connection instanceof PDOConnection) {
            throw new \RuntimeException('The fixture requires ThinkPHP PDO query support.');
        }
        $query = $connection->newQuery();
        if (!$query instanceof Query) {
            throw new \RuntimeException('The fixture requires ThinkPHP SQL query support.');
        }

        return $query->table($table);
    }
}
