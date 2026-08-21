<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Authorization;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PeanutAdmin\DataPermission\Constraint\PdoQueryConstraintCompiler;
use PeanutAdmin\DataPermission\Engine\DataPermissionEngine;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PeanutAdmin\Kernel\Auth\TenantContext;
use Throwable;

final readonly class ResourceProviderContractHarness
{
    public function __construct(
        private PDO $pdo,
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
        [$sql, $parameters] = $this->authorizedSelect($operation, $targets, $namePrefix);
        $statement = $this->pdo->prepare($sql . ' ORDER BY record.id');
        $statement->execute($parameters);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function detail(
        int $recordId,
        string $operation,
        TypedResourceTargetCollection $targets,
    ): ?array {
        [$sql, $parameters] = $this->authorizedSelect($operation, $targets);
        $sql .= ' AND record.id = :record_id';
        $parameters['record_id'] = $recordId;
        $this->trace->record($sql, $parameters);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

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
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO fixture_record (tenant_id, project_id, created_by_member_id, name)
VALUES (:tenant_id, :project_id, :member_id, :name)
SQL);
        $statement->execute([
            'tenant_id' => $this->context->tenantId,
            'project_id' => $projectId,
            'member_id' => $this->context->memberId,
            'name' => $payload['name'],
        ]);

        return (int) $this->pdo->lastInsertId();
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
            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE fixture_record SET name = :name
WHERE tenant_id = :tenant_id AND id = :record_id
SQL);
            $statement->execute([
                'name' => $name,
                'tenant_id' => $this->context->tenantId,
                'record_id' => $recordId,
            ]);

            return $statement->rowCount() === 1;
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
            $statement = $this->pdo->prepare(<<<'SQL'
DELETE FROM fixture_record WHERE tenant_id = :tenant_id AND id = :record_id
SQL);
            $statement->execute([
                'tenant_id' => $this->context->tenantId,
                'record_id' => $recordId,
            ]);

            return $statement->rowCount() === 1;
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
            [$sql, $parameters] = $this->authorizedSelect($operation, $targets);
            $placeholders = [];
            foreach ($recordIds as $index => $recordId) {
                $parameter = 'record_' . $index;
                $placeholders[] = ':' . $parameter;
                $parameters[$parameter] = $recordId;
            }
            $sql .= ' AND record.id IN (' . implode(', ', $placeholders) . ')'
                . ' AND record.project_id = :primary_project_id FOR UPDATE';
            $parameters['primary_project_id'] = $projectId;
            $this->trace->record($sql, $parameters);
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
            $authorizedIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
            if (count(array_unique($authorizedIds)) !== count(array_unique($recordIds))) {
                throw new DataAuthorizationException('AUTHZ_DATA_DENIED', 'The batch contains a denied record.');
            }

            $update = $this->pdo->prepare(
                'UPDATE fixture_record SET name = :name WHERE tenant_id = :tenant_id'
                . ' AND project_id = :project_id AND id IN (' . implode(', ', $placeholders) . ')',
            );
            $update->execute([
                'name' => $name,
                'tenant_id' => $this->context->tenantId,
                'project_id' => $projectId,
                ...array_filter(
                    $parameters,
                    static fn(string $key): bool => str_starts_with($key, 'record_'),
                    ARRAY_FILTER_USE_KEY,
                ),
            ]);
            $this->recordMultiTargetAudit($operation, $recordIds);

            return $update->rowCount();
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
            foreach ($rows as $row) {
                $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO fixture_record (tenant_id, project_id, created_by_member_id, name)
VALUES (:tenant_id, :project_id, :member_id, :name)
SQL);
                $statement->execute([
                    'tenant_id' => $this->context->tenantId,
                    'project_id' => $row['project_id'],
                    'member_id' => $this->context->memberId,
                    'name' => $row['name'],
                ]);
            }

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
     * @return array{string, array<string, int|string>}
     */
    private function authorizedSelect(
        string $operation,
        ?TypedResourceTargetCollection $targets,
        ?string $namePrefix = null,
    ): array {
        $compiled = (new PdoQueryConstraintCompiler())->compile($this->engine->queryConstraint(
            $this->context,
            $this->resourceKey,
            $operation,
            $targets ?? new TypedResourceTargetCollection(),
        ));
        $sql = 'SELECT record.* FROM fixture_record record WHERE ' . $compiled->sql;
        $parameters = $compiled->parameters;
        if ($namePrefix !== null) {
            $sql .= ' AND record.name LIKE :name_prefix';
            $parameters['name_prefix'] = $namePrefix . '%';
        }
        $this->trace->record($sql, $parameters);

        return [$sql, $parameters];
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
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_tenant_audit_event (
    tenant_id, event_type, action, outcome,
    actor_tenant_id, actor_tenant_member_id, actor_account_id, actor_type,
    target_resource_type, target_count, target_set_digest,
    authorization_basis_json, request_id, metadata_json, occurred_at
) VALUES (
    :tenant_id, 'fixture.batch.updated', :action, 'success',
    :actor_tenant_id, :member_id, :account_id, 'member',
    'fixture.record', :target_count, :target_digest,
    :authorization_basis, :request_id, :metadata, :occurred_at
)
SQL);
        $statement->execute([
            'tenant_id' => $this->context->tenantId,
            'action' => $action,
            'actor_tenant_id' => $this->context->tenantId,
            'member_id' => $this->context->memberId,
            'account_id' => $this->context->accountId,
            'target_count' => count($normalized),
            'target_digest' => hash('sha256', implode('|', $normalized)),
            'authorization_basis' => json_encode(['audience' => 'tenant'], JSON_THROW_ON_ERROR),
            'request_id' => $this->context->requestId,
            'metadata' => json_encode(['target_ids_recorded' => false], JSON_THROW_ON_ERROR),
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
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
