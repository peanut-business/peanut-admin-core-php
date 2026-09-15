<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Organization\Application;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Persistence\Model\Department;
use PeanutAdmin\Kernel\Persistence\Model\Tenant;
use PeanutAdmin\Kernel\Persistence\Model\TenantMember;
use Throwable;
use think\db\Raw;
use think\facade\Db;

final readonly class DepartmentAdminService
{
    private const MAX_DEPTH = 10;

    public function __construct(private AuditService $audit) {}

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function list(int $tenantId, PageRequest $page): array
    {
        $query = Department::where('tenant_id', $tenantId);
        $total = (int) (clone $query)->count();
        $rows = $query->field('id,parent_id,code,name,sort_order,status,revision')
            ->order('sort_order')->order('id')->limit($page->offset(), $page->pageSize)->select()->toArray();

        return ['items' => array_values(array_map($this->normalize(...), $rows)), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $departmentId): array
    {
        $row = Department::where('tenant_id', $tenantId)->where('id', $departmentId)
            ->field('id,parent_id,code,name,sort_order,status,revision')->find()?->toArray();

        return $row === null ? throw AdminAccessException::notFound() : $this->normalize($row);
    }

    /** @return array<string, mixed> */
    public function create(
        TenantContext $actor,
        string $code,
        string $name,
        ?int $parentId,
        int $sortOrder,
    ): array {
        return $this->transaction(function () use ($actor, $code, $name, $parentId, $sortOrder): array {
            $this->lockTenant($actor->tenantId);
            if ($parentId !== null) {
                $this->requireActive($actor->tenantId, $parentId, true);
                if ($this->depth($actor->tenantId, $parentId) >= self::MAX_DEPTH) {
                    throw AdminAccessException::invalid('DEPARTMENT_DEPTH_EXCEEDED', 'Department depth cannot exceed 10.');
                }
            }
            $now = $this->now();
            $departmentId = (int) Department::insertGetId([
                'tenant_id' => $actor->tenantId,
                'parent_id' => $parentId,
                'code' => $code,
                'name' => $name,
                'sort_order' => $sortOrder,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit($actor, 'tenant.department.created', 'core.department.create', $departmentId);

            return $this->get($actor->tenantId, $departmentId);
        });
    }

    /** @return array<string, mixed> */
    public function update(
        TenantContext $actor,
        int $departmentId,
        string $code,
        string $name,
        int $sortOrder,
        int $expectedRevision,
    ): array {
        return $this->transaction(function () use (
            $actor, $departmentId, $code, $name, $sortOrder, $expectedRevision,
        ): array {
            $department = $this->requireDepartment($actor->tenantId, $departmentId, true);
            $this->assertRevision($department, $expectedRevision);
            $now = $this->now();
            if (Department::where('tenant_id', $actor->tenantId)->where('id', $departmentId)
                ->where('revision', $expectedRevision)->update([
                    'code' => $code,
                    'name' => $name,
                    'sort_order' => $sortOrder,
                    'revision' => new Raw('revision + 1'),
                    'updated_at' => $now,
                ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit($actor, 'tenant.department.updated', 'core.department.update', $departmentId);

            return $this->get($actor->tenantId, $departmentId);
        });
    }

    /** @return array<string, mixed> */
    public function move(
        TenantContext $actor,
        int $departmentId,
        ?int $newParentId,
        int $expectedRevision,
    ): array {
        return $this->transaction(function () use ($actor, $departmentId, $newParentId, $expectedRevision): array {
            $department = $this->requireDepartment($actor->tenantId, $departmentId, true);
            $this->assertRevision($department, $expectedRevision);
            if ($newParentId === $departmentId) {
                throw AdminAccessException::invalid('DEPARTMENT_CYCLE', 'A department cannot be its own parent.');
            }
            $parentDepth = 0;
            if ($newParentId !== null) {
                $this->requireActive($actor->tenantId, $newParentId, true);
                if ($this->isDescendant($actor->tenantId, $departmentId, $newParentId)) {
                    throw AdminAccessException::invalid('DEPARTMENT_CYCLE', 'Department moves cannot create a cycle.');
                }
                $parentDepth = $this->depth($actor->tenantId, $newParentId);
            }
            if ($parentDepth + $this->subtreeDepth($actor->tenantId, $departmentId) > self::MAX_DEPTH) {
                throw AdminAccessException::invalid('DEPARTMENT_DEPTH_EXCEEDED', 'Department depth cannot exceed 10.');
            }
            $now = $this->now();
            if (Department::where('tenant_id', $actor->tenantId)->where('id', $departmentId)
                ->where('revision', $expectedRevision)->update([
                    'parent_id' => $newParentId,
                    'revision' => new Raw('revision + 1'),
                    'updated_at' => $now,
                ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit($actor, 'tenant.department.moved', 'core.department.move', $departmentId);

            return $this->get($actor->tenantId, $departmentId);
        });
    }

    /** @return array<string, mixed> */
    public function archive(TenantContext $actor, int $departmentId, int $expectedRevision): array
    {
        return $this->transaction(function () use ($actor, $departmentId, $expectedRevision): array {
            $department = $this->requireDepartment($actor->tenantId, $departmentId, true);
            $this->assertRevision($department, $expectedRevision);
            if ($department['status'] === 'archived') {
                throw AdminAccessException::conflict('DEPARTMENT_ALREADY_ARCHIVED', 'The department is already archived.');
            }
            $childCount = Department::where('tenant_id', $actor->tenantId)
                ->where('parent_id', $departmentId)->where('status', '<>', 'archived')->count();
            $memberCount = TenantMember::where('tenant_id', $actor->tenantId)
                ->where('primary_department_id', $departmentId)
                ->whereIn('status', ['pending', 'active', 'suspended'])->count();
            if ((int) $childCount !== 0 || (int) $memberCount !== 0) {
                throw AdminAccessException::conflict(
                    'DEPARTMENT_NOT_EMPTY',
                    'Move child departments and current members before archiving.',
                );
            }
            $now = $this->now();
            if (Department::where('tenant_id', $actor->tenantId)->where('id', $departmentId)
                ->where('revision', $expectedRevision)->update([
                    'status' => 'archived',
                    'archived_at' => $now,
                    'revision' => new Raw('revision + 1'),
                    'updated_at' => $now,
                ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit($actor, 'tenant.department.archived', 'core.department.archive', $departmentId);

            return $this->get($actor->tenantId, $departmentId);
        });
    }

    /** @return array<string, mixed> */
    private function requireDepartment(int $tenantId, int $departmentId, bool $forUpdate): array
    {
        $query = Department::where('tenant_id', $tenantId)->where('id', $departmentId);
        if ($forUpdate) {
            $query->lock(true);
        }

        return $query->find()?->toArray() ?? throw AdminAccessException::notFound();
    }

    private function requireActive(int $tenantId, int $departmentId, bool $forUpdate): void
    {
        if ($this->requireDepartment($tenantId, $departmentId, $forUpdate)['status'] !== 'active') {
            throw AdminAccessException::invalid('DEPARTMENT_INACTIVE', 'The parent department must be active.');
        }
    }

    /** @param array<string, mixed> $department */
    private function assertRevision(array $department, int $expectedRevision): void
    {
        if ((int) $department['revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
    }

    private function lockTenant(int $tenantId): void
    {
        if (Tenant::where('id', $tenantId)->where('status', 'active')->lock(true)->value('id') === null) {
            throw new AdminAccessException('TENANT_STATUS_INVALID', 403, 'The tenant is not active.');
        }
    }

    private function depth(int $tenantId, int $departmentId): int
    {
        $depth = 0;
        $current = $departmentId;
        while ($current !== 0 && $depth <= self::MAX_DEPTH) {
            $parent = Department::where('tenant_id', $tenantId)->where('id', $current)->value('parent_id');
            ++$depth;
            $current = $parent === null ? 0 : (int) $parent;
        }

        return $depth;
    }

    private function subtreeDepth(int $tenantId, int $departmentId): int
    {
        $depth = 0;
        $frontier = [$departmentId];
        while ($frontier !== [] && $depth <= self::MAX_DEPTH) {
            ++$depth;
            $frontier = array_map('intval', Department::where('tenant_id', $tenantId)
                ->whereIn('parent_id', $frontier)->column('id'));
        }

        return $depth;
    }

    private function isDescendant(int $tenantId, int $departmentId, int $possibleDescendantId): bool
    {
        $frontier = [$departmentId];
        for ($depth = 0; $depth <= self::MAX_DEPTH && $frontier !== []; ++$depth) {
            if (in_array($possibleDescendantId, $frontier, true)) {
                return true;
            }
            $frontier = array_map('intval', Department::where('tenant_id', $tenantId)
                ->whereIn('parent_id', $frontier)->column('id'));
        }

        return false;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'parent_id' => $row['parent_id'] === null ? null : (string) $row['parent_id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'sort_order' => (int) $row['sort_order'],
            'status' => $row['status'],
            'revision' => (string) $row['revision'],
        ];
    }

    private function bumpTenant(int $tenantId, string $now): void
    {
        Tenant::where('id', $tenantId)->update([
            'authorization_revision' => new Raw('authorization_revision + 1'),
            'updated_at' => $now,
        ]);
    }

    private function recordAudit(TenantContext $actor, string $eventType, string $action, int $departmentId): void
    {
        $this->audit->tenantMember(
            context: $actor,
            eventType: $eventType,
            action: $action,
            targetResourceType: 'department',
            targetResourceId: (string) $departmentId,
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
                throw AdminAccessException::conflict('DEPARTMENT_CONFLICT', 'Department code or relation conflicts.');
            }

            throw $exception;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
