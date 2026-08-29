<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use PDOException;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantColumnScope;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;
use PeanutAdmin\Settings\Application\SettingException;
use PeanutAdmin\Settings\Definition\SettingDefinition;
use PeanutAdmin\Settings\Definition\SettingDefinitionRegistry;
use Throwable;

final readonly class PdoSettingRepository
{
    private TenantColumnScope $tenantScope;

    public function __construct(
        private PDO $pdo,
        TenantPersistenceMode $mode = TenantPersistenceMode::TenantScoped,
        ?int $instanceTenantId = null,
    ) {
        $this->tenantScope = new TenantColumnScope($mode, $instanceTenantId);
        $this->tenantScope->assertRuntimeConfigured();
    }

    /** @template T
     * @param callable(): T $operation
     * @return T
     */
    public function atomically(callable $operation): mixed
    {
        return $this->transaction($operation);
    }

    /** @return array{inserted: int, updated: int, retired: int} */
    public function synchronize(SettingDefinitionRegistry $registry, DateTimeImmutable $now): array
    {
        return $this->transaction(function () use ($registry, $now): array {
            $moduleKeys = $registry->moduleKeys();
            if ($moduleKeys === []) {
                return ['inserted' => 0, 'updated' => 0, 'retired' => 0];
            }

            $existing = $this->definitionsForModules($moduleKeys);
            $declared = [];
            $counts = ['inserted' => 0, 'updated' => 0, 'retired' => 0];
            foreach ($registry->all() as $definition) {
                $qualifiedKey = $definition->qualifiedKey();
                $declared[$qualifiedKey] = true;
                $row = $existing[$qualifiedKey] ?? null;
                if ($row === null) {
                    $this->insertDefinition($definition, $now);
                    ++$counts['inserted'];
                    continue;
                }
                if ((string) $row['definition_digest'] === $definition->digest
                    && (string) $row['status'] === 'active') {
                    continue;
                }
                $this->updateDefinition((int) $row['id'], $definition, $now);
                ++$counts['updated'];
            }

            foreach ($existing as $qualifiedKey => $row) {
                if (isset($declared[$qualifiedKey]) || (string) $row['status'] === 'retired') {
                    continue;
                }
                $statement = $this->pdo->prepare(<<<'SQL'
UPDATE pa_setting_definition
SET status = 'retired', revision = revision + 1, updated_at = :updated_at
WHERE id = :id AND status = 'active'
SQL);
                $statement->execute([
                    'updated_at' => $this->date($now),
                    'id' => (int) $row['id'],
                ]);
                $counts['retired'] += $statement->rowCount();
            }

            return $counts;
        });
    }

    /**
     * @param array{value_json: ?string, ciphertext: ?string, nonce: ?string, key_id: ?string} $storage
     * @return array<string, mixed>
     */
    public function writeDeployment(
        SettingDefinition $definition,
        string $state,
        array $storage,
        int $operatorId,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        ?string $ifMatch,
        ?string $ifNoneMatch,
    ): array {
        return $this->writeValue(
            $definition,
            'deployment',
            $state,
            $storage,
            null,
            $operatorId,
            null,
            null,
            $effectiveAt,
            $expiresAt,
            $ifMatch,
            $ifNoneMatch,
        );
    }

    /**
     * @param array{value_json: ?string, ciphertext: ?string, nonce: ?string, key_id: ?string} $storage
     * @return array<string, mixed>
     */
    public function writeTenant(
        SettingDefinition $definition,
        string $state,
        array $storage,
        int $tenantId,
        int $memberId,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        ?string $ifMatch,
        ?string $ifNoneMatch,
    ): array {
        return $this->writeValue(
            $definition,
            'tenant',
            $state,
            $storage,
            $tenantId,
            $memberId,
            null,
            null,
            $effectiveAt,
            $expiresAt,
            $ifMatch,
            $ifNoneMatch,
        );
    }

    /**
     * @param array{value_json: ?string, ciphertext: ?string, nonce: ?string, key_id: ?string} $storage
     * @return array<string, mixed>
     */
    public function writeTarget(
        SettingDefinition $definition,
        string $state,
        array $storage,
        int $tenantId,
        int $memberId,
        string $targetResourceKey,
        string $targetId,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        ?string $ifMatch,
        ?string $ifNoneMatch,
    ): array {
        return $this->writeValue(
            $definition,
            'target',
            $state,
            $storage,
            $tenantId,
            $memberId,
            $targetResourceKey,
            $targetId,
            $effectiveAt,
            $expiresAt,
            $ifMatch,
            $ifNoneMatch,
        );
    }

    /**
     * @return array{
     *     definition: array<string, mixed>,
     *     deployment: array<string, mixed>|null,
     *     tenant: array<string, mixed>|null,
     *     target: array<string, mixed>|null
     * }
     */
    public function resolutionSnapshot(
        SettingDefinition $definition,
        int $tenantId,
        ?string $targetResourceKey = null,
        ?string $targetId = null,
    ): array {
        if (($targetResourceKey === null) !== ($targetId === null)) {
            throw SettingException::notFound();
        }
        $this->assertTenantContext($tenantId);

        return $this->transaction(function () use (
            $definition,
            $tenantId,
            $targetResourceKey,
            $targetId,
        ): array {
            if (!$this->exists(
                'SELECT id FROM pa_tenant WHERE id = :tenant_id',
                ['tenant_id' => $tenantId],
            )) {
                throw SettingException::notFound();
            }
            $definitionRow = $this->requireDefinition($definition);
            $definitionId = (int) $definitionRow['id'];

            return [
                'definition' => $definitionRow,
                'deployment' => $definition->allows('deployment')
                    ? $this->fetchOne(
                        'SELECT * FROM pa_setting_deployment_value WHERE definition_id = :definition_id',
                        ['definition_id' => $definitionId],
                    )
                    : null,
                'tenant' => $definition->allows('tenant')
                    ? $this->fetchOne(
                        'SELECT * FROM pa_setting_tenant_value WHERE '
                            . $this->tenantScope->where('definition_id = :definition_id'),
                        $this->tenantScope->bindings($tenantId, ['definition_id' => $definitionId]),
                    )
                    : null,
                'target' => $definition->allows('target') && $targetResourceKey !== null && $targetId !== null
                    ? $this->fetchOne(
                        'SELECT * FROM pa_setting_target_value WHERE '
                            . $this->tenantScope->where(
                                'definition_id = :definition_id '
                                    . 'AND target_resource_key = :target_resource_key AND target_id = :target_id',
                            ),
                        $this->tenantScope->bindings($tenantId, [
                        'definition_id' => $definitionId,
                        'target_resource_key' => $targetResourceKey,
                        'target_id' => $targetId,
                        ]),
                    )
                    : null,
            ];
        });
    }

    /**
     * @return array{
     *     definition: array<string, mixed>,
     *     deployment: array<string, mixed>|null
     * }
     */
    public function deploymentSnapshot(SettingDefinition $definition): array
    {
        return $this->transaction(function () use ($definition): array {
            $definitionRow = $this->requireDefinition($definition);

            return [
                'definition' => $definitionRow,
                'deployment' => $definition->allows('deployment')
                    ? $this->fetchOne(
                        'SELECT * FROM pa_setting_deployment_value WHERE definition_id = :definition_id',
                        ['definition_id' => (int) $definitionRow['id']],
                    )
                    : null,
            ];
        });
    }

    public function assertCurrentDefinition(SettingDefinition $definition, bool $forShare = false): void
    {
        $this->assertStorageMode();
        $this->requireDefinition($definition, $forShare);
    }

    /**
     * @param array{value_json: ?string, ciphertext: ?string, nonce: ?string, key_id: ?string} $storage
     * @return array<string, mixed>
     */
    private function writeValue(
        SettingDefinition $definition,
        string $scope,
        string $state,
        array $storage,
        ?int $tenantId,
        int $actorId,
        ?string $targetResourceKey,
        ?string $targetId,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        ?string $ifMatch,
        ?string $ifNoneMatch,
    ): array {
        if (!$definition->allows($scope) || !in_array($state, ['set', 'unset'], true)) {
            throw SettingException::invalid('SETTING_SCOPE_INVALID', 'The setting does not allow the requested scope.');
        }
        if ($scope !== 'deployment') {
            $this->assertTenantContext($tenantId);
        }
        $this->assertValidInterval($effectiveAt, $expiresAt);

        return $this->transaction(function () use (
            $definition,
            $scope,
            $state,
            $storage,
            $tenantId,
            $actorId,
            $targetResourceKey,
            $targetId,
            $effectiveAt,
            $expiresAt,
            $ifMatch,
            $ifNoneMatch,
        ): array {
            if ($scope === 'deployment') {
                if (!$this->exists(
                    'SELECT id FROM pa_platform_operator WHERE id = :operator_id',
                    ['operator_id' => $actorId],
                )) {
                    throw SettingException::notFound('SETTING_ACTOR_UNAUTHORIZED');
                }
            } elseif ($tenantId === null || !$this->exists(<<<'SQL'
SELECT id FROM pa_tenant_member
WHERE tenant_id = :tenant_id AND id = :member_id
SQL, ['tenant_id' => $tenantId, 'member_id' => $actorId])) {
                throw SettingException::notFound('SETTING_TARGET_UNAUTHORIZED');
            }

            $definitionRow = $this->requireDefinition($definition, true);
            $definitionId = (int) $definitionRow['id'];
            try {
                $existing = $this->currentValue(
                    $scope,
                    $definitionId,
                    $tenantId,
                    $targetResourceKey,
                    $targetId,
                    true,
                );
                $revision = $this->assertPrecondition($existing, $ifMatch, $ifNoneMatch);
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $row = [
                    'definition_id' => $definitionId,
                    'value_state' => $state,
                    'value_json' => $storage['value_json'],
                    'ciphertext' => $storage['ciphertext'],
                    'nonce' => $storage['nonce'],
                    'key_id' => $storage['key_id'],
                    'revision' => $revision,
                    'effective_at' => $this->date($effectiveAt),
                    'expires_at' => $expiresAt === null ? null : $this->date($expiresAt),
                    'created_at' => $existing['created_at'] ?? $this->date($now),
                    'updated_at' => $this->date($now),
                ];
                if ($scope !== 'deployment') {
                    $row = [...$this->tenantScope->bindings((int) $tenantId), ...$row];
                    $row['updated_by_member_id'] = $actorId;
                } else {
                    $row['updated_by_operator_id'] = $actorId;
                }
                if ($scope === 'target') {
                    $row['target_resource_key'] = $targetResourceKey;
                    $row['target_id'] = $targetId;
                }

                if ($existing === null) {
                    $this->insertValue($scope, $row);
                    $row['id'] = (int) $this->pdo->lastInsertId();
                } else {
                    $this->updateValue($scope, (int) $existing['id'], $revision - 1, $row);
                    $row['id'] = (int) $existing['id'];
                }

                return $row;
            } catch (PDOException $exception) {
                $competition = $this->competitionConflict($exception);
                if ($competition !== null) {
                    throw $competition;
                }

                throw $exception;
            }
        });
    }

    /** @param list<string> $moduleKeys
     * @return array<string, array<string, mixed>>
     */
    private function definitionsForModules(array $moduleKeys): array
    {
        $placeholders = implode(', ', array_fill(0, count($moduleKeys), '?'));
        $statement = $this->pdo->prepare(
            "SELECT * FROM pa_setting_definition WHERE module_key IN ({$placeholders}) FOR UPDATE",
        );
        $statement->execute($moduleKeys);
        $result = [];
        while (($row = $statement->fetch()) !== false) {
            if (is_array($row)) {
                $result[(string) $row['module_key'] . ':' . (string) $row['setting_key']] = $row;
            }
        }

        return $result;
    }

    private function insertDefinition(SettingDefinition $definition, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_setting_definition (
  module_key, setting_key, name, description, schema_json, required_flag, secret_flag,
  deployment_scope_flag, tenant_scope_flag, target_scope_flag,
  target_resource_key, target_operation, default_json, definition_digest,
  status, revision, created_at, updated_at
) VALUES (
  :module_key, :setting_key, :name, :description, :schema_json, :required_flag, :secret_flag,
  :deployment_scope_flag, :tenant_scope_flag, :target_scope_flag,
  :target_resource_key, :target_operation, :default_json, :definition_digest,
  'active', 1, :created_at, :updated_at
)
SQL);
        $statement->execute($this->definitionValues($definition, $now, true));
    }

    private function updateDefinition(
        int $id,
        SettingDefinition $definition,
        DateTimeImmutable $now,
    ): void {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE pa_setting_definition SET
  name = :name, description = :description, schema_json = :schema_json,
  required_flag = :required_flag, secret_flag = :secret_flag,
  deployment_scope_flag = :deployment_scope_flag, tenant_scope_flag = :tenant_scope_flag,
  target_scope_flag = :target_scope_flag, target_resource_key = :target_resource_key,
  target_operation = :target_operation, default_json = :default_json,
  definition_digest = :definition_digest, status = 'active',
  revision = revision + 1, updated_at = :updated_at
WHERE id = :id
SQL);
        $values = $this->definitionValues($definition, $now, false);
        $values['id'] = $id;
        $statement->execute($values);
    }

    /** @return array<string, bool|int|string|null> */
    private function definitionValues(
        SettingDefinition $definition,
        DateTimeImmutable $now,
        bool $includeIdentity,
    ): array {
        $values = [
            'name' => $definition->name,
            'description' => $definition->description,
            'schema_json' => $this->json($definition->schema),
            'required_flag' => (int) $definition->required,
            'secret_flag' => (int) $definition->secret,
            'deployment_scope_flag' => (int) $definition->allows('deployment'),
            'tenant_scope_flag' => (int) $definition->allows('tenant'),
            'target_scope_flag' => (int) $definition->allows('target'),
            'target_resource_key' => $definition->targetResourceKey,
            'target_operation' => $definition->targetOperation,
            'default_json' => $definition->hasDefault ? $this->json($definition->defaultValue) : null,
            'definition_digest' => $definition->digest,
            'updated_at' => $this->date($now),
        ];
        if ($includeIdentity) {
            $values['module_key'] = $definition->moduleKey;
            $values['setting_key'] = $definition->key;
            $values['created_at'] = $this->date($now);
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function requireDefinition(SettingDefinition $definition, bool $forShare = false): array
    {
        $row = $this->fetchOne(<<<'SQL'
SELECT * FROM pa_setting_definition
WHERE module_key = :module_key AND setting_key = :setting_key AND status = 'active'
SQL . ($forShare ? ' FOR SHARE' : ''), [
            'module_key' => $definition->moduleKey,
            'setting_key' => $definition->key,
        ]);
        if ($row === null || !hash_equals((string) $row['definition_digest'], $definition->digest)) {
            throw SettingException::notFound();
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function currentValue(
        string $scope,
        int $definitionId,
        ?int $tenantId,
        ?string $targetResourceKey,
        ?string $targetId,
        bool $forUpdate,
    ): ?array {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        return match ($scope) {
            'deployment' => $this->fetchOne(
                'SELECT * FROM pa_setting_deployment_value WHERE definition_id = :definition_id' . $suffix,
                ['definition_id' => $definitionId],
            ),
            'tenant' => $this->fetchOne(
                'SELECT * FROM pa_setting_tenant_value WHERE '
                    . $this->tenantScope->where('definition_id = :definition_id') . $suffix,
                $this->tenantScope->bindings((int) $tenantId, ['definition_id' => $definitionId]),
            ),
            'target' => $this->fetchOne(
                'SELECT * FROM pa_setting_target_value WHERE '
                    . $this->tenantScope->where(
                        'definition_id = :definition_id '
                            . 'AND target_resource_key = :target_resource_key AND target_id = :target_id',
                    ) . $suffix,
                $this->tenantScope->bindings((int) $tenantId, [
                'definition_id' => $definitionId,
                'target_resource_key' => $targetResourceKey,
                'target_id' => $targetId,
                ]),
            ),
            default => throw SettingException::invalid('SETTING_SCOPE_INVALID', 'The setting scope is invalid.'),
        };
    }

    /** @param array<string, mixed> $row */
    private function insertValue(string $scope, array $row): void
    {
        [$table, $columns] = $this->valueTable($scope);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
        ));
        $values = array_intersect_key($row, array_flip($columns));
        $statement->execute($values);
    }

    /** @param array<string, mixed> $row */
    private function updateValue(string $scope, int $id, int $expectedRevision, array $row): void
    {
        [$table, $columns] = $this->valueTable($scope);
        $mutable = array_values(array_diff($columns, [
            'definition_id', 'tenant_id', 'target_resource_key', 'target_id', 'created_at',
        ]));
        $assignments = array_map(static fn(string $column): string => $column . ' = :' . $column, $mutable);
        $statement = $this->pdo->prepare(sprintf(
            'UPDATE %s SET %s WHERE id = :id AND revision = :expected_revision',
            $table,
            implode(', ', $assignments),
        ));
        $values = array_intersect_key($row, array_flip($mutable));
        $values['id'] = $id;
        $values['expected_revision'] = $expectedRevision;
        $statement->execute($values);
        if ($statement->rowCount() !== 1) {
            throw SettingException::revisionMismatch();
        }
    }

    /** @return array{string, list<string>} */
    private function valueTable(string $scope): array
    {
        $common = [
            'definition_id', 'value_state', 'value_json', 'ciphertext', 'nonce', 'key_id',
            'revision', 'effective_at', 'expires_at',
        ];

        return match ($scope) {
            'deployment' => ['pa_setting_deployment_value', [
                ...$common, 'updated_by_operator_id', 'created_at', 'updated_at',
            ]],
            'tenant' => ['pa_setting_tenant_value', [
                ...($this->tenantScope->usesTenantColumn() ? ['tenant_id'] : []),
                ...$common, 'updated_by_member_id', 'created_at', 'updated_at',
            ]],
            'target' => ['pa_setting_target_value', [
                ...($this->tenantScope->usesTenantColumn() ? ['tenant_id'] : []),
                'definition_id', 'target_resource_key', 'target_id',
                ...array_slice($common, 1), 'updated_by_member_id', 'created_at', 'updated_at',
            ]],
            default => throw SettingException::invalid('SETTING_SCOPE_INVALID', 'The setting scope is invalid.'),
        };
    }

    /** @param array<string, mixed>|null $existing */
    private function assertPrecondition(?array $existing, ?string $ifMatch, ?string $ifNoneMatch): int
    {
        if ($existing === null) {
            if ($ifMatch === null && $ifNoneMatch === null) {
                throw SettingException::preconditionRequired();
            }
            if ($ifMatch !== null || $ifNoneMatch !== '*') {
                throw SettingException::revisionMismatch();
            }

            return 1;
        }
        if ($ifMatch === null && $ifNoneMatch === null) {
            throw SettingException::preconditionRequired();
        }
        $revision = (int) $existing['revision'];
        if ($ifNoneMatch !== null || $ifMatch !== self::etag($revision)) {
            throw SettingException::revisionMismatch();
        }

        return $revision + 1;
    }

    private static function etag(int $revision): string
    {
        return '"rev-' . $revision . '"';
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $parameters): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $parameters */
    private function exists(string $sql, array $parameters): bool
    {
        return $this->fetchOne($sql, $parameters) !== null;
    }

    private function json(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw SettingException::invalid('SETTING_VALUE_INVALID', 'The setting value cannot be encoded.');
        }
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function assertValidInterval(
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
    ): void {
        if (((int) $effectiveAt->format('u')) % 1000 !== 0
            || ($expiresAt !== null && ((int) $expiresAt->format('u')) % 1000 !== 0)
            || ($expiresAt !== null && $expiresAt <= $effectiveAt)) {
            throw SettingException::invalid(
                'SETTING_INTERVAL_INVALID',
                'Setting timestamps must use exact millisecond precision and a valid interval.',
            );
        }
    }

    private function competitionConflict(PDOException $exception): ?SettingException
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $duplicate = $sqlState === '23000' && $driverCode === 1062;
        $deadlock = $sqlState === '40001' && $driverCode === 1213;
        $lockTimeout = $sqlState === 'HY000' && $driverCode === 1205;

        return $duplicate || $deadlock || $lockTimeout
            ? SettingException::conflict()
            : null;
    }

    /** @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation): mixed
    {
        $this->assertStorageMode();
        if ($this->pdo->inTransaction()) {
            return $operation();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->rollBackTransaction();
            throw $exception;
        }
    }

    private function rollBackTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function assertTenantContext(?int $tenantId): void
    {
        if ($tenantId === null) {
            throw SettingException::notFound();
        }
        try {
            $this->tenantScope->assertTenantId($tenantId);
        } catch (\RuntimeException) {
            throw SettingException::notFound();
        }
    }

    private function assertStorageMode(): void
    {
        $this->tenantScope->assertStorageMode($this->pdo, [
            'pa_setting_tenant_value',
            'pa_setting_target_value',
        ]);
    }
}
