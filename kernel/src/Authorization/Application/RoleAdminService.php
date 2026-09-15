<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Application;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Persistence\Model\RolePermission;
use Throwable;
use think\facade\Db;

final readonly class RoleAdminService
{
    public function __construct(private AuditService $audit) {}

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function list(int $tenantId, PageRequest $page): array
    {
        $query = Db::name('role')->where('tenant_id', $tenantId);
        $total = (int) (clone $query)->count();
        $rows = $query->field('id,key,name,description,is_builtin,status,authorization_revision')
            ->order('id')->limit($page->offset(), $page->pageSize)->select()->toArray();

        return ['items' => $this->hydratePermissions($tenantId, array_values($rows)), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $roleId): array
    {
        $row = Db::name('role')->where('tenant_id', $tenantId)->where('id', $roleId)
            ->field('id,key,name,description,is_builtin,status,authorization_revision')->find();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }

        return $this->hydratePermissions($tenantId, [$row])[0];
    }

    /** @return array<string, mixed> */
    public function create(TenantContext $actor, string $key, string $name, ?string $description): array
    {
        if (str_starts_with($key, 'core.') || str_starts_with($key, 'platform.')) {
            throw AdminAccessException::invalid('ROLE_KEY_RESERVED', 'The role key uses a reserved namespace.');
        }

        return $this->transaction(function () use ($actor, $key, $name, $description): array {
            $this->lockTenant($actor->tenantId);
            $now = $this->now();
            $roleId = (int) Db::name('role')->insertGetId([
                'tenant_id' => $actor->tenantId,
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'is_builtin' => 0,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit($actor, 'tenant.role.created', 'core.role.create', $roleId);

            return $this->get($actor->tenantId, $roleId);
        });
    }

    /** @return array<string, mixed> */
    public function update(
        TenantContext $actor,
        int $roleId,
        string $name,
        ?string $description,
        int $expectedRevision,
    ): array {
        return $this->transaction(function () use ($actor, $roleId, $name, $description, $expectedRevision): array {
            $role = $this->requireRole($actor->tenantId, $roleId, true);
            $this->assertRevision($role, $expectedRevision);
            $now = $this->now();
            if (Db::name('role')->where('tenant_id', $actor->tenantId)->where('id', $roleId)
                ->where('authorization_revision', $expectedRevision)->update([
                    'name' => $name,
                    'description' => $description,
                    'authorization_revision' => Db::raw('authorization_revision + 1'),
                    'updated_at' => $now,
                ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit($actor, 'tenant.role.updated', 'core.role.update', $roleId);

            return $this->get($actor->tenantId, $roleId);
        });
    }

    /** @return array<string, mixed> */
    public function archive(TenantContext $actor, int $roleId, int $expectedRevision): array
    {
        return $this->transaction(function () use ($actor, $roleId, $expectedRevision): array {
            $role = $this->requireRole($actor->tenantId, $roleId, true);
            $this->assertRevision($role, $expectedRevision);
            if ((int) $role['is_builtin'] === 1) {
                throw AdminAccessException::conflict('BUILTIN_ROLE_IMMUTABLE', 'Built-in roles cannot be archived.');
            }
            if ($role['status'] === 'archived') {
                throw AdminAccessException::conflict('ROLE_ALREADY_ARCHIVED', 'The role is already archived.');
            }
            $now = $this->now();
            if (Db::name('role')->where('tenant_id', $actor->tenantId)->where('id', $roleId)
                ->where('authorization_revision', $expectedRevision)->update([
                    'status' => 'archived',
                    'archived_at' => $now,
                    'authorization_revision' => Db::raw('authorization_revision + 1'),
                    'updated_at' => $now,
                ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit($actor, 'tenant.role.archived', 'core.role.archive', $roleId);

            return $this->get($actor->tenantId, $roleId);
        });
    }

    /** @param list<string> $permissionKeys
     * @return array<string, mixed>
     */
    public function replacePermissions(
        TenantContext $actor,
        int $roleId,
        array $permissionKeys,
        int $expectedRevision,
    ): array {
        $permissionKeys = array_values(array_unique($permissionKeys));

        return $this->transaction(function () use ($actor, $roleId, $permissionKeys, $expectedRevision): array {
            $role = $this->requireRole($actor->tenantId, $roleId, true);
            $this->assertRevision($role, $expectedRevision);
            if ((int) $role['is_builtin'] === 1 && $role['key'] === 'core.tenant-owner') {
                throw AdminAccessException::conflict(
                    'TENANT_OWNER_PERMISSIONS_FIXED',
                    'Tenant owner core permissions are fixed by the release catalog.',
                );
            }
            $permissions = $this->assignablePermissions($actor->tenantId, $permissionKeys);
            if (count($permissions) !== count($permissionKeys)) {
                throw AdminAccessException::invalid(
                    'PERMISSION_NOT_ASSIGNABLE',
                    'A permission is retired, belongs to the platform, or its module is unavailable.',
                );
            }
            Db::name('role_permission')->where('tenant_id', $actor->tenantId)->where('role_id', $roleId)->delete();
            $now = $this->now();
            if ($permissions !== []) {
                Db::name('role_permission')->insertAll(array_map(
                    static fn(array $permission): array => [
                        'tenant_id' => $actor->tenantId,
                        'role_id' => $roleId,
                        'permission_id' => (int) $permission['id'],
                        'granted_by_member_id' => $actor->memberId,
                        'granted_at' => $now,
                    ],
                    $permissions,
                ));
            }
            if (Db::name('role')->where('tenant_id', $actor->tenantId)->where('id', $roleId)
                ->where('authorization_revision', $expectedRevision)->update([
                    'authorization_revision' => Db::raw('authorization_revision + 1'),
                    'updated_at' => $now,
                ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit(
                $actor,
                'tenant.role.permissions-replaced',
                'core.role.permission.assign',
                $roleId,
            );

            return $this->get($actor->tenantId, $roleId);
        });
    }

    /** @return array<string, mixed> */
    private function requireRole(int $tenantId, int $roleId, bool $forUpdate): array
    {
        $query = Db::name('role')->where('tenant_id', $tenantId)->where('id', $roleId);
        if ($forUpdate) {
            $query->lock(true);
        }

        return $query->find() ?? throw AdminAccessException::notFound();
    }

    /** @param array<string, mixed> $role */
    private function assertRevision(array $role, int $expectedRevision): void
    {
        if ((int) $role['authorization_revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
    }

    /** @param list<string> $permissionKeys
     * @return list<array{id: int, key: string}>
     */
    private function assignablePermissions(int $tenantId, array $permissionKeys): array
    {
        if ($permissionKeys === []) {
            return [];
        }
        $modules = Db::name('tenant_module')->where('tenant_id', $tenantId)->where('status', 'enabled')
            ->where(function ($query): void {
                $query->whereNull('effective_at')->whereOr('effective_at', '<=', Db::raw('UTC_TIMESTAMP(3)'));
            })->where(function ($query): void {
                $query->whereNull('expires_at')->whereOr('expires_at', '>', Db::raw('UTC_TIMESTAMP(3)'));
            })->column('module_key');

        /** @var list<array{id: int, key: string}> $permissions */
        $permissions = Db::name('permission')->whereIn('key', $permissionKeys)->where('status', 'active')
            ->whereNotLike('key', 'platform.%')->whereIn('module_key', array_values(array_unique(['core', ...$modules])))
            ->field('id,key')->order('key')->select()->toArray();

        return $permissions;
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function hydratePermissions(int $tenantId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $roleIds = array_map('intval', array_column($rows, 'id'));
        $permissionKeys = [];
        foreach (RolePermission::alias('role_permission')
            ->join('permission permission', 'permission.id = role_permission.permission_id')
            ->where('role_permission.tenant_id', $tenantId)->whereIn('role_permission.role_id', $roleIds)
            ->field(['role_permission.role_id', 'permission.key'])->order('permission.key')->select()->toArray() as $permission) {
            $permissionKeys[(int) $permission['role_id']][] = (string) $permission['key'];
        }
        foreach ($rows as &$row) {
            $row = [
                'id' => (string) $row['id'],
                'key' => $row['key'],
                'name' => $row['name'],
                'description' => $row['description'],
                'is_builtin' => (int) $row['is_builtin'] === 1,
                'status' => $row['status'],
                'revision' => (string) $row['authorization_revision'],
                'permission_keys' => array_values(array_unique($permissionKeys[(int) $row['id']] ?? [])),
            ];
        }
        unset($row);

        return $rows;
    }

    private function lockTenant(int $tenantId): void
    {
        if (Db::name('tenant')->where('id', $tenantId)->where('status', 'active')->lock(true)->value('id') === null) {
            throw new AdminAccessException('TENANT_STATUS_INVALID', 403, 'The tenant is not active.');
        }
    }

    private function bumpTenant(int $tenantId, string $now): void
    {
        Db::name('tenant')->where('id', $tenantId)->update([
            'authorization_revision' => Db::raw('authorization_revision + 1'),
            'updated_at' => $now,
        ]);
    }

    private function recordAudit(TenantContext $actor, string $eventType, string $action, int $roleId): void
    {
        $this->audit->tenantMember(
            context: $actor,
            eventType: $eventType,
            action: $action,
            targetResourceType: 'role',
            targetResourceId: (string) $roleId,
        );
    }

    /** @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation): mixed
    {
        try {
            return Db::transaction($operation);
        } catch (Throwable $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw AdminAccessException::conflict('ROLE_CONFLICT', 'Role key or relation conflicts.');
            }

            throw $exception;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
