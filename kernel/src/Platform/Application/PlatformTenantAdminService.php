<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use JsonException;
use PDO;
use PDOException;
use PDOStatement;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\TenantModuleManager;
use PeanutAdmin\Kernel\Tenancy\TenantStatus;
use Throwable;

final readonly class PlatformTenantAdminService
{
    private const TENANT_CODE_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D';

    public function __construct(
        private PDO $pdo,
        private TenantModuleManager $modules,
    ) {}

    /** @return array<string, mixed> */
    public function createTenant(
        int $operatorId,
        int $operatorAccountId,
        string $code,
        string $name,
        string $displayName,
        string $locale,
        string $timezone,
        string $requestId,
    ): array {
        $code = trim($code);
        if (preg_match(self::TENANT_CODE_PATTERN, $code) !== 1 || strlen($code) > 64) {
            throw AdminAccessException::invalid('TENANT_CODE_INVALID', 'The tenant code is invalid.');
        }
        [$name, $displayName, $locale, $timezone] = $this->validateTenantFields(
            $name,
            $displayName,
            $locale,
            $timezone,
        );

        try {
            return $this->transaction(function () use (
                $operatorId,
                $operatorAccountId,
                $code,
                $name,
                $displayName,
                $locale,
                $timezone,
                $requestId,
            ): array {
                $this->requireOperator($operatorId, $operatorAccountId);
                $now = $this->now();
                $this->execute(<<<'SQL'
INSERT INTO pa_tenant (
    code, name, display_name, status, locale, timezone, created_at, updated_at
) VALUES (
    :code, :name, :display_name, 'provisioning', :locale, :timezone, :created_at, :updated_at
)
SQL, [
                    'code' => $code,
                    'name' => $name,
                    'display_name' => $displayName,
                    'locale' => $locale,
                    'timezone' => $timezone,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $tenantId = (int) $this->pdo->lastInsertId();
                $this->execute(<<<'SQL'
INSERT INTO pa_role (
    tenant_id, `key`, name, description, is_builtin, status, created_at, updated_at
) VALUES (
    :tenant_id, 'core.tenant-owner', 'Tenant Owner',
    'Built-in owner role for tenant governance.', 1, 'active', :created_at, :updated_at
)
SQL, ['tenant_id' => $tenantId, 'created_at' => $now, 'updated_at' => $now]);
                $tenant = $this->tenant($tenantId);
                $this->platformAudit(
                    $operatorId,
                    $operatorAccountId,
                    'tenant.created',
                    'platform.tenant.create',
                    'tenant',
                    (string) $tenantId,
                    $requestId,
                    null,
                    $tenant,
                    ['tenant_id' => (string) $tenantId],
                );

                return $tenant;
            });
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw AdminAccessException::conflict('TENANT_CODE_CONFLICT', 'The tenant code is already in use.');
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function updateTenant(
        int $operatorId,
        int $operatorAccountId,
        int $tenantId,
        int $expectedRevision,
        string $name,
        string $displayName,
        string $locale,
        string $timezone,
        string $changeReason,
        string $requestId,
    ): array {
        [$name, $displayName, $locale, $timezone] = $this->validateTenantFields(
            $name,
            $displayName,
            $locale,
            $timezone,
        );
        $changeReason = $this->changeReason($changeReason);

        return $this->transaction(function () use (
            $operatorId,
            $operatorAccountId,
            $tenantId,
            $expectedRevision,
            $name,
            $displayName,
            $locale,
            $timezone,
            $changeReason,
            $requestId,
        ): array {
            $this->requireOperator($operatorId, $operatorAccountId);
            $before = $this->tenant($tenantId, true);
            if ($before['status'] === TenantStatus::Closed->value) {
                throw AdminAccessException::conflict('TENANT_CLOSED', 'A closed tenant cannot be updated.');
            }
            $this->assertRevision($before, $expectedRevision);
            if ($this->execute(<<<'SQL'
UPDATE pa_tenant
SET name = :name, display_name = :display_name, locale = :locale, timezone = :timezone,
    revision = revision + 1, updated_at = :updated_at
WHERE id = :tenant_id AND revision = :expected_revision
SQL, [
                'name' => $name,
                'display_name' => $displayName,
                'locale' => $locale,
                'timezone' => $timezone,
                'updated_at' => $this->now(),
                'tenant_id' => $tenantId,
                'expected_revision' => $expectedRevision,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $after = $this->tenant($tenantId);
            $this->platformAudit(
                $operatorId,
                $operatorAccountId,
                'tenant.updated',
                'platform.tenant.update',
                'tenant',
                (string) $tenantId,
                $requestId,
                $before,
                $after,
                ['tenant_id' => (string) $tenantId, 'change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /** @return array<string, mixed> */
    public function transitionTenant(
        int $operatorId,
        int $operatorAccountId,
        int $tenantId,
        int $expectedRevision,
        TenantStatus $next,
        string $changeReason,
        string $requestId,
    ): array {
        $changeReason = $this->changeReason($changeReason);

        return $this->transaction(function () use (
            $operatorId,
            $operatorAccountId,
            $tenantId,
            $expectedRevision,
            $next,
            $changeReason,
            $requestId,
        ): array {
            $this->requireOperator($operatorId, $operatorAccountId);
            $before = $this->tenant($tenantId, true);
            $this->assertRevision($before, $expectedRevision);
            $current = TenantStatus::from((string) $before['status']);
            try {
                $current->transitionTo($next);
            } catch (DomainException) {
                throw AdminAccessException::conflict(
                    'TENANT_STATUS_TRANSITION_INVALID',
                    "Tenant cannot transition from {$current->value} to {$next->value}.",
                );
            }
            if ($next === TenantStatus::Active && !$this->activeOwnerExists($tenantId)) {
                throw AdminAccessException::conflict(
                    'TENANT_OWNER_REQUIRED',
                    'The tenant requires an active owner before activation.',
                );
            }

            $now = $this->now();
            if ($this->execute(<<<'SQL'
UPDATE pa_tenant
SET status = :status,
    security_revision = security_revision + 1,
    revision = revision + 1,
    activated_at = CASE WHEN :activation_status = 'active' THEN :activated_at ELSE activated_at END,
    suspended_at = CASE WHEN :suspension_status = 'suspended' THEN :suspended_at ELSE suspended_at END,
    closed_at = CASE WHEN :closed_status = 'closed' THEN :closed_at ELSE closed_at END,
    updated_at = :updated_at
WHERE id = :tenant_id AND revision = :expected_revision
SQL, [
                'status' => $next->value,
                'activation_status' => $next->value,
                'suspension_status' => $next->value,
                'closed_status' => $next->value,
                'activated_at' => $now,
                'suspended_at' => $now,
                'closed_at' => $now,
                'updated_at' => $now,
                'tenant_id' => $tenantId,
                'expected_revision' => $expectedRevision,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $after = $this->tenant($tenantId);
            $eventType = 'tenant.' . match ($next) {
                TenantStatus::Active => 'activated',
                TenantStatus::Suspended => 'suspended',
                TenantStatus::Closed => 'closed',
                TenantStatus::Provisioning => throw new DomainException('Provisioning is not a lifecycle target.'),
            };
            $metadata = ['tenant_id' => (string) $tenantId, 'change_reason' => $changeReason];
            $this->platformAudit(
                $operatorId,
                $operatorAccountId,
                $eventType,
                'platform.tenant.lifecycle',
                'tenant',
                (string) $tenantId,
                $requestId,
                $before,
                $after,
                $metadata,
            );
            $this->tenantAudit(
                $operatorId,
                $operatorAccountId,
                $tenantId,
                $eventType,
                'platform.tenant.lifecycle',
                'tenant',
                (string) $tenantId,
                $requestId,
                $before,
                $after,
                $metadata,
            );

            return $after;
        });
    }

    /** @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function enableModule(
        int $operatorId,
        int $operatorAccountId,
        int $tenantId,
        string $moduleKey,
        array $config,
        string $source,
        ?DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        string $changeReason,
        string $requestId,
    ): array {
        $changeReason = $this->changeReason($changeReason);
        if (!in_array($source, ['manual', 'product_profile', 'license'], true)) {
            throw AdminAccessException::invalid('MODULE_SOURCE_INVALID', 'The module source is invalid.');
        }
        if ($effectiveAt !== null && $expiresAt !== null && $expiresAt <= $effectiveAt) {
            throw AdminAccessException::invalid(
                'MODULE_PERIOD_INVALID',
                'Module expires_at must be later than effective_at.',
            );
        }

        try {
            return $this->transaction(function () use (
                $operatorId,
                $operatorAccountId,
                $tenantId,
                $moduleKey,
                $config,
                $source,
                $effectiveAt,
                $expiresAt,
                $changeReason,
                $requestId,
            ): array {
                $this->requireOperator($operatorId, $operatorAccountId);
                $tenant = $this->tenant($tenantId, true);
                if ($tenant['status'] !== TenantStatus::Active->value) {
                    throw AdminAccessException::conflict(
                        'MODULE_TENANT_DISABLED',
                        'Only an active tenant can enable a module.',
                    );
                }
                $before = $this->tenantModule($tenantId, $moduleKey, true);
                $this->modules->enable(
                    $tenantId,
                    $moduleKey,
                    $config,
                    new DateTimeImmutable('now', new DateTimeZone('UTC')),
                    $source,
                    $effectiveAt,
                    $expiresAt,
                );
                $after = $this->tenantModule($tenantId, $moduleKey)
                    ?? throw new AdminAccessException('MODULE_WRITE_FAILED', 500, 'The module state could not be loaded.');
                $metadata = [
                    'tenant_id' => (string) $tenantId,
                    'module_key' => $moduleKey,
                    'change_reason' => $changeReason,
                ];
                $this->platformAudit(
                    $operatorId,
                    $operatorAccountId,
                    'tenant-module.enabled',
                    'platform.tenant.module.manage',
                    'tenant-module',
                    $tenantId . ':' . $moduleKey,
                    $requestId,
                    $this->moduleAuditSnapshot($before),
                    $this->moduleAuditSnapshot($after),
                    $metadata,
                );
                $this->tenantAudit(
                    $operatorId,
                    $operatorAccountId,
                    $tenantId,
                    'tenant-module.enabled',
                    'platform.tenant.module.manage',
                    'tenant-module',
                    $moduleKey,
                    $requestId,
                    $this->moduleAuditSnapshot($before),
                    $this->moduleAuditSnapshot($after),
                    $metadata,
                );

                return $after;
            });
        } catch (ModuleException $exception) {
            throw $this->moduleError($exception);
        }
    }

    /** @return array<string, mixed> */
    public function disableModule(
        int $operatorId,
        int $operatorAccountId,
        int $tenantId,
        string $moduleKey,
        string $changeReason,
        string $requestId,
    ): array {
        $changeReason = $this->changeReason($changeReason);

        try {
            return $this->transaction(function () use (
                $operatorId,
                $operatorAccountId,
                $tenantId,
                $moduleKey,
                $changeReason,
                $requestId,
            ): array {
                $this->requireOperator($operatorId, $operatorAccountId);
                $tenant = $this->tenant($tenantId, true);
                if ($tenant['status'] !== TenantStatus::Active->value) {
                    throw AdminAccessException::conflict(
                        'MODULE_TENANT_DISABLED',
                        'Only an active tenant can disable a module.',
                    );
                }
                $before = $this->tenantModule($tenantId, $moduleKey, true);
                if ($before === null) {
                    throw AdminAccessException::notFound();
                }
                $this->modules->disable(
                    $tenantId,
                    $moduleKey,
                    new DateTimeImmutable('now', new DateTimeZone('UTC')),
                );
                $after = $this->tenantModule($tenantId, $moduleKey)
                    ?? throw new AdminAccessException('MODULE_WRITE_FAILED', 500, 'The module state could not be loaded.');
                $metadata = [
                    'tenant_id' => (string) $tenantId,
                    'module_key' => $moduleKey,
                    'change_reason' => $changeReason,
                ];
                $this->platformAudit(
                    $operatorId,
                    $operatorAccountId,
                    'tenant-module.disabled',
                    'platform.tenant.module.manage',
                    'tenant-module',
                    $tenantId . ':' . $moduleKey,
                    $requestId,
                    $this->moduleAuditSnapshot($before),
                    $this->moduleAuditSnapshot($after),
                    $metadata,
                );
                $this->tenantAudit(
                    $operatorId,
                    $operatorAccountId,
                    $tenantId,
                    'tenant-module.disabled',
                    'platform.tenant.module.manage',
                    'tenant-module',
                    $moduleKey,
                    $requestId,
                    $this->moduleAuditSnapshot($before),
                    $this->moduleAuditSnapshot($after),
                    $metadata,
                );

                return $after;
            });
        } catch (ModuleException $exception) {
            throw $this->moduleError($exception);
        }
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    private function validateTenantFields(
        string $name,
        string $displayName,
        string $locale,
        string $timezone,
    ): array {
        $name = trim($name);
        $displayName = trim($displayName);
        $locale = trim($locale);
        $timezone = trim($timezone);
        if ($name === '' || mb_strlen($name) > 160 || $displayName === '' || mb_strlen($displayName) > 160) {
            throw AdminAccessException::invalid('TENANT_NAME_INVALID', 'Tenant names are required and limited to 160 characters.');
        }
        if (preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $locale) !== 1 || strlen($locale) > 16) {
            throw AdminAccessException::invalid('TENANT_LOCALE_INVALID', 'The tenant locale is invalid.');
        }
        if (strlen($timezone) > 64 || !in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw AdminAccessException::invalid('TENANT_TIMEZONE_INVALID', 'The tenant timezone is invalid.');
        }

        return [$name, $displayName, $locale, $timezone];
    }

    private function changeReason(string $changeReason): string
    {
        $changeReason = trim($changeReason);
        if ($changeReason === '' || mb_strlen($changeReason) > 255) {
            throw AdminAccessException::invalid(
                'CHANGE_REASON_REQUIRED',
                'A change reason of at most 255 characters is required.',
            );
        }

        return $changeReason;
    }

    /** @param array<string, mixed> $tenant */
    private function assertRevision(array $tenant, int $expectedRevision): void
    {
        if ((int) $tenant['revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
    }

    private function requireOperator(int $operatorId, int $operatorAccountId): void
    {
        $operator = $this->fetchOne(<<<'SQL'
SELECT id FROM pa_platform_operator
WHERE id = :operator_id AND account_id = :account_id AND status = 'active'
FOR UPDATE
SQL, ['operator_id' => $operatorId, 'account_id' => $operatorAccountId]);
        if ($operator === null) {
            throw new AdminAccessException('PLATFORM_OPERATOR_INACTIVE', 403, 'An active platform operator is required.');
        }
    }

    private function activeOwnerExists(int $tenantId): bool
    {
        return $this->fetchOne(<<<'SQL'
SELECT tm.id
FROM pa_tenant_member tm
JOIN pa_account a ON a.id = tm.account_id AND a.status = 'active'
JOIN pa_member_role mr ON mr.tenant_id = tm.tenant_id AND mr.tenant_member_id = tm.id
JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
WHERE tm.tenant_id = :tenant_id AND tm.status = 'active'
  AND r.`key` = 'core.tenant-owner' AND r.is_builtin = 1 AND r.status = 'active'
LIMIT 1
SQL, ['tenant_id' => $tenantId]) !== null;
    }

    /** @return array<string, mixed> */
    private function tenant(int $tenantId, bool $forUpdate = false): array
    {
        $row = $this->fetchOne(<<<SQL
SELECT id, code, name, display_name, status, locale, timezone,
       security_revision, authorization_revision, revision,
       activated_at, suspended_at, closed_at, created_at, updated_at
FROM pa_tenant WHERE id = :tenant_id
SQL . ($forUpdate ? ' FOR UPDATE' : ''), ['tenant_id' => $tenantId]);
        if ($row === null) {
            throw AdminAccessException::notFound();
        }

        return $this->normalize($row);
    }

    /** @return array<string, mixed>|null */
    private function tenantModule(int $tenantId, string $moduleKey, bool $forUpdate = false): ?array
    {
        $row = $this->fetchOne(<<<SQL
SELECT id, tenant_id, module_key, status, source, config_json, config_revision,
       authorization_revision, effective_at, expires_at, enabled_at, disabled_at,
       disabled_reason, created_at, updated_at
FROM pa_tenant_module
WHERE tenant_id = :tenant_id AND module_key = :module_key
SQL . ($forUpdate ? ' FOR UPDATE' : ''), ['tenant_id' => $tenantId, 'module_key' => $moduleKey]);
        if ($row === null) {
            return null;
        }
        try {
            $config = $row['config_json'] === null
                ? []
                : json_decode((string) $row['config_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AdminAccessException('DATABASE_DATA_INVALID', 500, 'Stored module configuration is invalid.');
        }
        $row['config'] = is_array($config) ? $config : [];
        unset($row['config_json']);

        return $this->normalize($row);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value !== null && ($key === 'id' || str_ends_with($key, '_id')
                || str_ends_with($key, '_revision') || $key === 'revision')) {
                $row[$key] = (string) $value;
            }
        }

        return $row;
    }

    /** @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, string> $metadata
     */
    private function platformAudit(
        int $operatorId,
        int $operatorAccountId,
        string $eventType,
        string $action,
        string $targetType,
        string $targetId,
        string $requestId,
        ?array $before,
        ?array $after,
        array $metadata,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_platform_audit_event (
    event_type, action, outcome, operator_id, account_id, target_type, target_id,
    request_id, before_json, after_json, metadata_json, occurred_at
) VALUES (
    :event_type, :action, 'success', :operator_id, :account_id, :target_type, :target_id,
    :request_id, :before_json, :after_json, :metadata_json, :occurred_at
)
SQL, [
            'event_type' => $eventType,
            'action' => $action,
            'operator_id' => $operatorId,
            'account_id' => $operatorAccountId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'request_id' => $requestId,
            'before_json' => $this->json($before),
            'after_json' => $this->json($after),
            'metadata_json' => $this->json($metadata),
            'occurred_at' => $this->now(),
        ]);
    }

    /** @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, string> $metadata
     */
    private function tenantAudit(
        int $operatorId,
        int $operatorAccountId,
        int $tenantId,
        string $eventType,
        string $action,
        string $targetType,
        string $targetId,
        string $requestId,
        ?array $before,
        ?array $after,
        array $metadata,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_tenant_audit_event (
    tenant_id, event_type, action, outcome, actor_account_id,
    actor_platform_operator_id, actor_type, target_resource_type,
    target_resource_id, target_count, request_id, before_json, after_json,
    metadata_json, occurred_at
) VALUES (
    :tenant_id, :event_type, :action, 'success', :actor_account_id,
    :operator_id, 'platform_operator', :target_type,
    :target_id, 1, :request_id, :before_json, :after_json,
    :metadata_json, :occurred_at
)
SQL, [
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'actor_account_id' => $operatorAccountId,
            'operator_id' => $operatorId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'request_id' => $requestId,
            'before_json' => $this->json($before),
            'after_json' => $this->json($after),
            'metadata_json' => $this->json($metadata),
            'occurred_at' => $this->now(),
        ]);
    }

    private function moduleError(ModuleException $exception): AdminAccessException
    {
        return new AdminAccessException(
            $exception->errorCode,
            $exception->errorCode === 'MODULE_CONFIG_INVALID' ? 422 : 409,
            $exception->getMessage(),
        );
    }

    /** @param array<string, mixed>|null $module
     * @return array<string, mixed>|null
     */
    private function moduleAuditSnapshot(?array $module): ?array
    {
        if ($module === null) {
            return null;
        }

        return array_intersect_key($module, array_flip([
            'id',
            'tenant_id',
            'module_key',
            'status',
            'source',
            'config_revision',
            'authorization_revision',
            'effective_at',
            'expires_at',
            'enabled_at',
            'disabled_at',
            'disabled_reason',
        ]));
    }

    /** @param array<string, mixed>|null $value */
    private function json(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, int|string|null> $parameters */
    private function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    /** @param array<string, int|string|null> $parameters
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $parameters = []): ?array
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function statement(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new AdminAccessException('DATABASE_ERROR', 500, 'Could not prepare the database operation.');
        }

        return $statement;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }

    /** @template T
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
