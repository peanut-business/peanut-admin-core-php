<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use JsonException;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\TenantModuleManager;
use PeanutAdmin\Kernel\Persistence\Model\TenantMember;
use PeanutAdmin\Kernel\Tenancy\TenantStatus;
use Throwable;
use think\facade\Db;

final readonly class PlatformTenantAdminService
{
    private const TENANT_CODE_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D';

    public function __construct(private TenantModuleManager $modules, private AuditService $audit) {}

    /** @return array<string, mixed> */
    public function createTenant(
        PlatformContext $actor,
        string $code,
        string $name,
        string $displayName,
        string $locale,
        string $timezone,
    ): array {
        $code = trim($code);
        if (preg_match(self::TENANT_CODE_PATTERN, $code) !== 1 || strlen($code) > 64) {
            throw AdminAccessException::invalid('TENANT_CODE_INVALID', 'The tenant code is invalid.');
        }
        [$name, $displayName, $locale, $timezone] = $this->validateTenantFields($name, $displayName, $locale, $timezone);

        return $this->transaction(function () use ($actor, $code, $name, $displayName, $locale, $timezone): array {
            $this->requireOperator($actor);
            $now = $this->now();
            $tenantId = (int) Db::name('tenant')->insertGetId([
                'code' => $code, 'name' => $name, 'display_name' => $displayName,
                'status' => TenantStatus::Provisioning->value, 'locale' => $locale, 'timezone' => $timezone,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            Db::name('role')->insert([
                'tenant_id' => $tenantId, 'key' => 'core.tenant-owner', 'name' => 'Tenant Owner',
                'description' => 'Built-in owner role for tenant governance.', 'is_builtin' => 1,
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $tenant = $this->tenant($tenantId);
            $this->audit->platform(
                $actor->operatorId, $actor->accountId, $actor->requestId, 'tenant.created',
                'platform.tenant.create', ['tenant_id' => (string) $tenantId],
                'tenant', (string) $tenantId, null, $tenant,
            );

            return $tenant;
        }, 'TENANT_CODE_CONFLICT', 'The tenant code is already in use.');
    }

    /** @return array<string, mixed> */
    public function updateTenant(
        PlatformContext $actor,
        int $tenantId,
        int $expectedRevision,
        string $name,
        string $displayName,
        string $locale,
        string $timezone,
        string $changeReason,
    ): array {
        [$name, $displayName, $locale, $timezone] = $this->validateTenantFields($name, $displayName, $locale, $timezone);
        $changeReason = $this->changeReason($changeReason);

        return Db::transaction(function () use (
            $actor, $tenantId, $expectedRevision, $name, $displayName, $locale, $timezone, $changeReason,
        ): array {
            $this->requireOperator($actor);
            $before = $this->tenant($tenantId, true);
            if ($before['status'] === TenantStatus::Closed->value) {
                throw AdminAccessException::conflict('TENANT_CLOSED', 'A closed tenant cannot be updated.');
            }
            $this->assertRevision($before, $expectedRevision);
            if (Db::name('tenant')->where('id', $tenantId)->where('revision', $expectedRevision)->update([
                'name' => $name, 'display_name' => $displayName, 'locale' => $locale, 'timezone' => $timezone,
                'revision' => Db::raw('revision + 1'), 'updated_at' => $this->now(),
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $after = $this->tenant($tenantId);
            $this->auditTenantChange($actor, 'tenant.updated', 'platform.tenant.update', $tenantId, $before, $after, $changeReason);

            return $after;
        });
    }

    /** @return array<string, mixed> */
    public function transitionTenant(
        PlatformContext $actor,
        int $tenantId,
        int $expectedRevision,
        TenantStatus $next,
        string $changeReason,
    ): array {
        $changeReason = $this->changeReason($changeReason);

        return Db::transaction(function () use ($actor, $tenantId, $expectedRevision, $next, $changeReason): array {
            $this->requireOperator($actor);
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
                throw AdminAccessException::conflict('TENANT_OWNER_REQUIRED', 'The tenant requires an active owner before activation.');
            }
            $now = $this->now();
            $changes = [
                'status' => $next->value, 'security_revision' => Db::raw('security_revision + 1'),
                'revision' => Db::raw('revision + 1'), 'updated_at' => $now,
            ];
            $lifecycleField = match ($next) {
                TenantStatus::Active => 'activated_at', TenantStatus::Suspended => 'suspended_at',
                TenantStatus::Closed => 'closed_at',
                TenantStatus::Provisioning => throw new DomainException('Provisioning is not a lifecycle target.'),
            };
            $changes[$lifecycleField] = $now;
            if (Db::name('tenant')->where('id', $tenantId)->where('revision', $expectedRevision)->update($changes) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $after = $this->tenant($tenantId);
            $eventType = 'tenant.' . ($next === TenantStatus::Active
                ? 'activated'
                : ($next === TenantStatus::Suspended ? 'suspended' : 'closed'));
            $this->auditTenantChange(
                $actor, $eventType, 'platform.tenant.lifecycle', $tenantId, $before, $after, $changeReason, true,
            );

            return $after;
        });
    }

    /** @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function enableModule(
        PlatformContext $actor,
        int $tenantId,
        string $moduleKey,
        array $config,
        string $source,
        ?DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        string $changeReason,
    ): array {
        $changeReason = $this->changeReason($changeReason);
        if (!in_array($source, ['manual', 'product_profile', 'license'], true)) {
            throw AdminAccessException::invalid('MODULE_SOURCE_INVALID', 'The module source is invalid.');
        }
        if ($effectiveAt !== null && $expiresAt !== null && $expiresAt <= $effectiveAt) {
            throw AdminAccessException::invalid('MODULE_PERIOD_INVALID', 'Module expires_at must be later than effective_at.');
        }
        try {
            return Db::transaction(function () use (
                $actor, $tenantId, $moduleKey, $config, $source, $effectiveAt, $expiresAt, $changeReason,
            ): array {
                $this->requireOperator($actor);
                if ($this->tenant($tenantId, true)['status'] !== TenantStatus::Active->value) {
                    throw AdminAccessException::conflict('MODULE_TENANT_DISABLED', 'Only an active tenant can enable a module.');
                }
                $before = $this->tenantModule($tenantId, $moduleKey, true);
                $this->modules->enable(
                    $tenantId, $moduleKey, $config, new DateTimeImmutable('now', new DateTimeZone('UTC')),
                    $source, $effectiveAt, $expiresAt,
                );
                $after = $this->tenantModule($tenantId, $moduleKey)
                    ?? throw new AdminAccessException('MODULE_WRITE_FAILED', 500, 'The module state could not be loaded.');
                $this->auditModuleChange($actor, $tenantId, $moduleKey, 'tenant-module.enabled', $before, $after, $changeReason);

                return $after;
            });
        } catch (ModuleException $exception) {
            throw $this->moduleError($exception);
        }
    }

    /** @return array<string, mixed> */
    public function disableModule(
        PlatformContext $actor,
        int $tenantId,
        string $moduleKey,
        string $changeReason,
    ): array {
        $changeReason = $this->changeReason($changeReason);
        try {
            return Db::transaction(function () use ($actor, $tenantId, $moduleKey, $changeReason): array {
                $this->requireOperator($actor);
                if ($this->tenant($tenantId, true)['status'] !== TenantStatus::Active->value) {
                    throw AdminAccessException::conflict('MODULE_TENANT_DISABLED', 'Only an active tenant can disable a module.');
                }
                $before = $this->tenantModule($tenantId, $moduleKey, true);
                if ($before === null) {
                    throw AdminAccessException::notFound();
                }
                $this->modules->disable($tenantId, $moduleKey, new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $after = $this->tenantModule($tenantId, $moduleKey)
                    ?? throw new AdminAccessException('MODULE_WRITE_FAILED', 500, 'The module state could not be loaded.');
                $this->auditModuleChange($actor, $tenantId, $moduleKey, 'tenant-module.disabled', $before, $after, $changeReason);

                return $after;
            });
        } catch (ModuleException $exception) {
            throw $this->moduleError($exception);
        }
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    private function validateTenantFields(string $name, string $displayName, string $locale, string $timezone): array
    {
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

    private function changeReason(string $value): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 255) {
            throw AdminAccessException::invalid('CHANGE_REASON_REQUIRED', 'A change reason of at most 255 characters is required.');
        }

        return $value;
    }

    /** @param array<string, mixed> $tenant */
    private function assertRevision(array $tenant, int $expectedRevision): void
    {
        if ((int) $tenant['revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
    }

    private function requireOperator(PlatformContext $actor): void
    {
        if (Db::name('platform_operator')->where('id', $actor->operatorId)->where('account_id', $actor->accountId)
            ->where('status', 'active')->lock(true)->value('id') === null) {
            throw new AdminAccessException('PLATFORM_OPERATOR_INACTIVE', 403, 'An active platform operator is required.');
        }
    }

    private function activeOwnerExists(int $tenantId): bool
    {
        return TenantMember::alias('member')
            ->join('account account', "account.id = member.account_id AND account.status = 'active'")
            ->join('member_role member_role', 'member_role.tenant_id = member.tenant_id AND member_role.tenant_member_id = member.id')
            ->join('role role', 'role.tenant_id = member_role.tenant_id AND role.id = member_role.role_id')
            ->where('member.tenant_id', $tenantId)->where('member.status', 'active')
            ->where('role.key', 'core.tenant-owner')->where('role.is_builtin', 1)->where('role.status', 'active')
            ->value('member.id') !== null;
    }

    /** @return array<string, mixed> */
    private function tenant(int $tenantId, bool $forUpdate = false): array
    {
        $query = Db::name('tenant')->where('id', $tenantId);
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->field(
            'id,code,name,display_name,status,locale,timezone,security_revision,authorization_revision,revision,'
            . 'activated_at,suspended_at,closed_at,created_at,updated_at',
        )->find();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }

        return $this->normalize($row);
    }

    /** @return array<string, mixed>|null */
    private function tenantModule(int $tenantId, string $moduleKey, bool $forUpdate = false): ?array
    {
        $query = Db::name('tenant_module')->where('tenant_id', $tenantId)->where('module_key', $moduleKey);
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->field(
            'id,tenant_id,module_key,status,source,config_json,config_revision,authorization_revision,'
            . 'effective_at,expires_at,enabled_at,disabled_at,disabled_reason,created_at,updated_at',
        )->find();
        if ($row === null) {
            return null;
        }
        try {
            $config = $row['config_json'] === null
                ? [] : json_decode((string) $row['config_json'], true, 512, JSON_THROW_ON_ERROR);
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

    /** @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function auditTenantChange(
        PlatformContext $actor,
        string $eventType,
        string $action,
        int $tenantId,
        array $before,
        array $after,
        string $changeReason,
        bool $alsoTenant = false,
    ): void {
        $metadata = ['tenant_id' => (string) $tenantId, 'change_reason' => $changeReason];
        $this->audit->platform(
            $actor->operatorId, $actor->accountId, $actor->requestId, $eventType, $action, $metadata,
            'tenant', (string) $tenantId, $before, $after,
        );
        if ($alsoTenant) {
            $this->audit->tenantPlatformOperator(
                $tenantId, $actor->operatorId, $actor->accountId, $eventType, $action, $actor->requestId,
                $metadata, 'tenant', (string) $tenantId, $before, $after,
            );
        }
    }

    /** @param array<string, mixed>|null $before
     * @param array<string, mixed> $after
     */
    private function auditModuleChange(
        PlatformContext $actor,
        int $tenantId,
        string $moduleKey,
        string $eventType,
        ?array $before,
        array $after,
        string $changeReason,
    ): void {
        $metadata = ['tenant_id' => (string) $tenantId, 'module_key' => $moduleKey, 'change_reason' => $changeReason];
        $before = $this->moduleAuditSnapshot($before);
        $after = $this->moduleAuditSnapshot($after);
        $this->audit->platform(
            $actor->operatorId, $actor->accountId, $actor->requestId, $eventType,
            'platform.tenant.module.manage', $metadata, 'tenant-module', $tenantId . ':' . $moduleKey, $before, $after,
        );
        $this->audit->tenantPlatformOperator(
            $tenantId, $actor->operatorId, $actor->accountId, $eventType,
            'platform.tenant.module.manage', $actor->requestId, $metadata, 'tenant-module', $moduleKey, $before, $after,
        );
    }

    private function moduleError(ModuleException $exception): AdminAccessException
    {
        return new AdminAccessException(
            $exception->errorCode, $exception->errorCode === 'MODULE_CONFIG_INVALID' ? 422 : 409, $exception->getMessage(),
        );
    }

    /** @param array<string, mixed>|null $module
     * @return array<string, mixed>|null
     */
    private function moduleAuditSnapshot(?array $module): ?array
    {
        return $module === null ? null : array_intersect_key($module, array_flip([
            'id', 'tenant_id', 'module_key', 'status', 'source', 'config_revision', 'authorization_revision',
            'effective_at', 'expires_at', 'enabled_at', 'disabled_at', 'disabled_reason',
        ]));
    }

    /** @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation, string $conflictCode, string $conflictMessage): mixed
    {
        try {
            return Db::transaction($operation);
        } catch (Throwable $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw AdminAccessException::conflict($conflictCode, $conflictMessage);
            }
            throw $exception;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
