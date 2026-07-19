<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Application;

use DateTimeZone;
use PDO;
use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog;
use PeanutAdmin\DataPermission\Policy\EffectivePolicySet;
use PeanutAdmin\DataPermission\Policy\PolicyRepository;
use PeanutAdmin\Kernel\Audit\AuditRepository;
use PeanutAdmin\Kernel\Auth\Clock;
use PeanutAdmin\Kernel\Auth\SystemClock;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Authorization\EffectivePermissionSet;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository;
use RuntimeException;
use Throwable;

final readonly class EffectiveAccessPreviewService
{
    public function __construct(
        private PDO $pdo,
        private TenantAuthorizationRepository $authorization,
        private ResourceOperationCatalog $catalog,
        private PolicyRepository $policies,
        private AuditRepository $audit,
        private Clock $clock = new SystemClock(),
    ) {}

    /**
     * @return array{
     *   data: array<string, mixed>,
     *   meta: array{page: int, page_size: int, total: int, total_pages: int}
     * }
     */
    public function preview(TenantContext $actor, int $memberId, PageRequest $page): array
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $this->buildPreview($actor, $memberId, $page);
            $this->audit->appendTenantMember(
                $actor,
                'tenant.member.effective-access.viewed',
                'core.member.effective-access.read',
                'member',
                (string) $memberId,
                null,
                null,
                1,
                null,
                [
                    'snapshot_revision' => $result['data']['snapshot_revision'],
                    'role_count' => count($result['data']['roles']),
                    'permission_count' => count($result['data']['permission_keys']),
                    'operation_count' => $result['meta']['total'],
                    'page' => $page->page,
                    'page_size' => $page->pageSize,
                ],
            );
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            $this->rollback($ownsTransaction);

            throw $exception;
        }
    }

    /**
     * @return array{
     *   data: array<string, mixed>,
     *   meta: array{page: int, page_size: int, total: int, total_pages: int}
     * }
     */
    private function buildPreview(TenantContext $actor, int $memberId, PageRequest $page): array
    {
        $member = $this->authorization->member($actor->tenantId, $memberId)
            ?? throw AdminAccessException::notFound();
        $roles = $this->authorization->activeRoles($actor->tenantId, $memberId);
        $rolesById = [];
        foreach ($roles as $role) {
            $rolesById[$role['id']] = $role['key'];
        }
        $permissions = $this->authorization->permissions($actor->tenantId, $memberId);
        $permissionKeys = $permissions->keys();
        sort($permissionKeys, SORT_STRING);
        $rbacRevision = $this->authorization->revision($actor->tenantId, $memberId);
        $catalogRevision = $this->catalog->registryRevision();
        $operationPage = $this->catalog->availableOperations($actor->tenantId, $page);
        $operationSummaries = [];
        $operationDigestParts = [];
        foreach ($operationPage['items'] as $operation) {
            $policyRevision = $this->policies->revision(
                $actor->tenantId,
                $memberId,
                $operation->id,
            );
            $policySet = $this->policies->load($actor->tenantId, $memberId, $operation->id);
            $operationSummaries[] = $this->operationSummary(
                $operation,
                $permissions,
                $policySet,
                $rolesById,
            );
            $operationDigestParts[] = $operation->resourceKey;
            $operationDigestParts[] = $operation->operation;
            $operationDigestParts[] = $policyRevision->value;
        }
        $snapshotRevision = hash('sha256', implode('|', [
            'p1-b02-v1',
            (string) $actor->tenantId,
            (string) $memberId,
            $rbacRevision,
            $catalogRevision,
            (string) $page->page,
            (string) $page->pageSize,
            ...$operationDigestParts,
        ]));
        $totalPages = (int) ceil($operationPage['total'] / $page->pageSize);

        return [
            'data' => [
                'preview_kind' => 'authorization_inputs',
                'evaluated_at' => $this->clock->now()
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format(DATE_ATOM),
                'snapshot_revision' => $snapshotRevision,
                'member' => [
                    'id' => (string) $member['id'],
                    'display_name' => $member['display_name'],
                    'status' => $member['status'],
                    'primary_department_id' => $member['primary_department_id'] === null
                        ? null
                        : (string) $member['primary_department_id'],
                    'effective' => $member['status'] === 'active',
                ],
                'roles' => array_map(static fn(array $role): array => [
                    'id' => (string) $role['id'],
                    'key' => $role['key'],
                    'name' => $role['name'],
                    'is_builtin' => $role['is_builtin'],
                ], $roles),
                'permission_keys' => $permissionKeys,
                'resource_operations' => $operationSummaries,
            ],
            'meta' => [
                'page' => $page->page,
                'page_size' => $page->pageSize,
                'total' => $operationPage['total'],
                'total_pages' => $totalPages,
            ],
        ];
    }

    /**
     * @param array<int, string> $rolesById
     * @return array<string, mixed>
     */
    private function operationSummary(
        ResourceOperation $operation,
        EffectivePermissionSet $permissions,
        EffectivePolicySet $policies,
        array $rolesById,
    ): array {
        $requiredPermissions = $operation->permissionKeys;
        sort($requiredPermissions, SORT_STRING);
        $functionalAllowed = $this->functionalAllowed($operation, $permissions);
        $groups = [];
        foreach ($policies->groups as $group) {
            $sourceRoleKey = $rolesById[$group->roleId] ?? null;
            if ($sourceRoleKey === null) {
                throw new RuntimeException('An effective policy references an unavailable active role.');
            }
            $groups[] = [
                'source_role_key' => $sourceRoleKey,
                'condition_match' => 'all',
                'conditions' => array_map(static fn($condition): array => [
                    'condition_key' => $condition->key,
                    'target_resource_key' => $condition->targetResourceKey,
                    'target_count' => $condition->targetCount,
                ], $group->conditions),
            ];
        }
        $mode = $this->mode($operation, $functionalAllowed, $groups !== []);
        $runtimeDecisionRequired = $functionalAllowed
            && $mode !== 'tenant_actor_denied'
            && (
                $operation->targetCardinality !== 'none'
                || in_array($operation->ownership, ['tenant_owned', 'business_target_owned', 'shared_master'], true)
                || in_array($operation->accessMode, ['rule_filtered', 'explicit_targets'], true)
            );

        return [
            'resource_key' => $operation->resourceKey,
            'module_key' => $operation->moduleKey,
            'operation' => $operation->operation,
            'ownership' => $operation->ownership,
            'access_mode' => $operation->accessMode,
            'target_cardinality' => $operation->targetCardinality,
            'permission_match' => $operation->permissionMatch,
            'required_permission_keys' => $requiredPermissions,
            'functional_allowed' => $functionalAllowed,
            'data_access' => [
                'mode' => $mode,
                'runtime_decision_required' => $runtimeDecisionRequired,
                'group_match' => 'any',
                'groups' => $groups,
            ],
        ];
    }

    private function functionalAllowed(
        ResourceOperation $operation,
        EffectivePermissionSet $permissions,
    ): bool {
        if ($operation->permissionKeys === []) {
            return false;
        }
        $results = array_map($permissions->allows(...), $operation->permissionKeys);

        return $operation->permissionMatch === 'all'
            ? !in_array(false, $results, true)
            : in_array(true, $results, true);
    }

    private function mode(
        ResourceOperation $operation,
        bool $functionalAllowed,
        bool $hasEffectiveGroups,
    ): string {
        return match (true) {
            !$functionalAllowed => 'functional_denied',
            $operation->accessMode === 'system_internal'
                || $operation->ownership === 'platform_internal' => 'tenant_actor_denied',
            $operation->accessMode === 'global_reference_read' => 'global_reference_read',
            $operation->accessMode === 'tenant_wide' => 'tenant_wide',
            $hasEffectiveGroups => 'conditional',
            default => 'no_effective_policy',
        };
    }

    private function rollback(bool $ownsTransaction): void
    {
        if ($ownsTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
