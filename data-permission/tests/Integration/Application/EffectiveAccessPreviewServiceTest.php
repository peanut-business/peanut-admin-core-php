<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Integration\Application;

use DateTimeImmutable;
use PDO;
use PeanutAdmin\DataPermission\Application\EffectiveAccessPreviewService;
use PeanutAdmin\DataPermission\Catalog\PdoResourceOperationCatalog;
use PeanutAdmin\DataPermission\Constraint\ColumnReference;
use PeanutAdmin\DataPermission\Constraint\PdoQueryConstraintCompiler;
use PeanutAdmin\DataPermission\Engine\DataPermissionEngine;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\DataPermission\Policy\PdoPolicyRepository;
use PeanutAdmin\DataPermission\Policy\PolicyCache;
use PeanutAdmin\DataPermission\Provider\ConditionProviderRegistry;
use PeanutAdmin\DataPermission\Provider\PdoDepartmentHierarchyProvider;
use PeanutAdmin\DataPermission\Provider\PdoTargetSetMembershipProvider;
use PeanutAdmin\DataPermission\Provider\ProviderColumnMap;
use PeanutAdmin\DataPermission\Provider\ResourceProviderRegistry;
use PeanutAdmin\DataPermission\Provider\SharedMasterScopeProviderRegistry;
use PeanutAdmin\DataPermission\Provider\StandardResourcePolicyProvider;
use PeanutAdmin\DataPermission\Target\TargetCatalogProviderRegistry;
use PeanutAdmin\DataPermission\Target\TargetResolverRegistry;
use PeanutAdmin\DataPermission\Tests\Integration\Schema\DataPermissionMigrationRunner;
use PeanutAdmin\Kernel\Audit\AuditRepository;
use PeanutAdmin\Kernel\Auth\Clock;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Kernel\Authorization\PdoTenantAuthorizationRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PdoAuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoAuditRepository;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;
use RuntimeException;

require_once dirname(__DIR__, 4) . '/kernel/tests/Integration/Schema/DatabaseTestCase.php';
require_once dirname(__DIR__) . '/Schema/DataPermissionMigrationRunner.php';

final class EffectiveAccessPreviewServiceTest extends DatabaseTestCase
{
    private const NOW = '2026-07-19 08:00:00.000';

    private int $tenantId;
    private int $actorAccountId;
    private int $actorMemberId;
    private int $subjectMemberId;
    private int $roleA;
    private int $roleB;
    private TenantContext $actor;
    private PdoTenantAuthorizationRepository $authorization;
    private PdoResourceOperationCatalog $catalog;
    private PdoPolicyRepository $policies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
        (new DataPermissionMigrationRunner(
            self::DATABASE,
            '127.0.0.1',
            (int) (getenv('MYSQL_PORT') ?: 3306),
            'root',
            getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev',
        ))->migrate();
        (new CorePermissionCatalogSynchronizer(
            new PdoAuthorizationCatalogRepository($this->database),
        ))->synchronize();

        $this->tenantId = $this->tenant('preview-alpha');
        $this->actorAccountId = $this->account('Preview administrator');
        $this->actorMemberId = $this->member($this->tenantId, $this->actorAccountId, 'Administrator');
        $subjectAccountId = $this->account('Preview subject');
        $this->subjectMemberId = $this->member($this->tenantId, $subjectAccountId, 'Subject');
        $this->roleA = $this->role('tenant.preview-a', 'Preview A');
        $this->roleB = $this->role('tenant.preview-b', 'Preview B');
        $disabledRole = $this->role('tenant.preview-disabled', 'Disabled role', 'disabled');
        foreach ([$this->roleB, $disabledRole, $this->roleA] as $roleId) {
            $this->assignRole($roleId);
        }
        $this->grant($this->roleA, 'core.member.read');
        $this->grant($this->roleB, 'core.member.read');
        $this->grant($this->roleB, 'core.role.read');
        $this->grant($disabledRole, 'core.audit.read');

        $this->seedOperations();
        $this->seedConditionalPolicies();
        $this->authorization = new PdoTenantAuthorizationRepository($this->database);
        $this->catalog = new PdoResourceOperationCatalog($this->database);
        $this->policies = new PdoPolicyRepository($this->database);
        $this->actor = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            '01J00000000000000000000000',
            $this->tenantId,
            $this->actorAccountId,
            $this->actorMemberId,
            'admin-web',
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
            1,
        ), 'req_effective_access');
    }

    public function testBuildsExactRedactedModesDigestAndOneAuditEvent(): void
    {
        $service = $this->service(new PdoAuditRepository($this->database));

        $result = $service->preview($this->actor, $this->subjectMemberId, new PageRequest(1, 100));
        $data = $result['data'];

        self::assertSame('authorization_inputs', $data['preview_kind']);
        self::assertSame('2026-07-19T08:00:00+00:00', $data['evaluated_at']);
        self::assertSame([
            'tenant.preview-a',
            'tenant.preview-b',
            'tenant.preview-expired',
            'tenant.preview-future',
            'tenant.preview-group-disabled',
            'tenant.preview-policy-disabled',
        ], array_column($data['roles'], 'key'));
        self::assertSame(['core.member.read', 'core.role.read'], $data['permission_keys']);
        self::assertSame(10, $result['meta']['total']);
        self::assertSame(1, $result['meta']['total_pages']);

        $operations = [];
        foreach ($data['resource_operations'] as $operation) {
            $operations[$operation['resource_key']] = $operation;
        }
        self::assertSame('functional_denied', $operations['preview.a-functional-denied']['data_access']['mode']);
        self::assertFalse($operations['preview.a-functional-denied']['functional_allowed']);
        self::assertSame('no_effective_policy', $operations['preview.b-any']['data_access']['mode']);
        self::assertTrue($operations['preview.b-any']['functional_allowed']);
        self::assertSame('tenant_actor_denied', $operations['preview.c-system']['data_access']['mode']);
        self::assertSame('tenant_actor_denied', $operations['preview.d-platform']['data_access']['mode']);
        self::assertSame('global_reference_read', $operations['preview.e-global']['data_access']['mode']);
        self::assertFalse($operations['preview.e-global']['data_access']['runtime_decision_required']);
        self::assertSame('tenant_wide', $operations['preview.f-tenant-wide']['data_access']['mode']);
        self::assertTrue($operations['preview.f-tenant-wide']['data_access']['runtime_decision_required']);
        self::assertSame('conditional', $operations['preview.g-conditional']['data_access']['mode']);
        self::assertSame('no_effective_policy', $operations['preview.h-no-policy']['data_access']['mode']);
        self::assertTrue($operations['preview.h-no-policy']['data_access']['runtime_decision_required']);
        self::assertSame('global_reference_read', $operations['preview.i-global-targeted']['data_access']['mode']);
        self::assertTrue($operations['preview.i-global-targeted']['data_access']['runtime_decision_required']);
        self::assertSame('functional_denied', $operations['preview.j-unbound']['data_access']['mode']);
        self::assertArrayNotHasKey('preview.z-unavailable', $operations);

        $groups = $operations['preview.g-conditional']['data_access']['groups'];
        self::assertSame('any', $operations['preview.g-conditional']['data_access']['group_match']);
        self::assertSame(
            ['tenant.preview-a', 'tenant.preview-b'],
            array_column($groups, 'source_role_key'),
        );
        self::assertSame('tenant.preview-a', $groups[0]['source_role_key']);
        self::assertSame('all', $groups[0]['condition_match']);
        self::assertSame([
            'condition_key' => 'core.specified_objects',
            'target_resource_key' => 'preview.project',
            'target_count' => 501,
        ], $groups[0]['conditions'][0]);
        self::assertSame('tenant.preview-b', $groups[1]['source_role_key']);
        self::assertSame('core.self', $groups[1]['conditions'][0]['condition_key']);
        self::assertStringNotContainsString('target_id', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('provider', json_encode($result, JSON_THROW_ON_ERROR));

        $policyRevisions = [];
        foreach ($data['resource_operations'] as $operation) {
            $catalogOperation = $this->catalog->find($operation['resource_key'], $operation['operation']);
            self::assertNotNull($catalogOperation);
            $policyRevisions[] = $this->policies->revision(
                $this->tenantId,
                $this->subjectMemberId,
                $catalogOperation->id,
            )->value;
        }
        $digestParts = [
            'p1-b02-v1',
            (string) $this->tenantId,
            (string) $this->subjectMemberId,
            $this->authorization->revision($this->tenantId, $this->subjectMemberId),
            $this->catalog->registryRevision(),
            '1',
            '100',
        ];
        foreach ($data['resource_operations'] as $index => $operation) {
            $digestParts[] = $operation['resource_key'];
            $digestParts[] = $operation['operation'];
            $digestParts[] = $policyRevisions[$index];
        }
        self::assertSame(hash('sha256', implode('|', $digestParts)), $data['snapshot_revision']);

        $event = $this->query(<<<'SQL'
SELECT event_type, action, actor_tenant_member_id, actor_account_id,
       target_resource_type, target_resource_id, target_count, metadata_json
FROM pa_tenant_audit_event
SQL)->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($event);
        self::assertSame('tenant.member.effective-access.viewed', $event['event_type']);
        self::assertSame('core.member.effective-access.read', $event['action']);
        self::assertSame($this->actorMemberId, (int) $event['actor_tenant_member_id']);
        self::assertSame($this->actorAccountId, (int) $event['actor_account_id']);
        self::assertSame('member', $event['target_resource_type']);
        self::assertSame((string) $this->subjectMemberId, $event['target_resource_id']);
        self::assertSame(1, (int) $event['target_count']);
        $metadata = json_decode((string) $event['metadata_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($data['snapshot_revision'], $metadata['snapshot_revision']);
        self::assertSame(6, $metadata['role_count']);
        self::assertSame(2, $metadata['permission_count']);
        self::assertSame(10, $metadata['operation_count']);
        self::assertArrayNotHasKey('permission_keys', $metadata);
    }

    public function testPreviewModesStayInParityWithDataPermissionEngine(): void
    {
        $preview = $this->service(new PdoAuditRepository($this->database))->preview(
            $this->actor,
            $this->subjectMemberId,
            new PageRequest(1, 100),
        );
        $summaries = [];
        foreach ($preview['data']['resource_operations'] as $summary) {
            $summaries[$summary['resource_key']] = $summary;
        }

        $accountId = (int) $this->query(<<<SQL
SELECT account_id FROM pa_tenant_member WHERE id = {$this->subjectMemberId}
SQL)->fetchColumn();
        $subject = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            2,
            '01J00000000000000000000001',
            $this->tenantId,
            $accountId,
            $this->subjectMemberId,
            'parity-test',
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
            1,
        ), 'req_effective_access_parity');
        $engine = $this->engine();

        foreach (['preview.a-functional-denied', 'preview.j-unbound'] as $resourceKey) {
            self::assertFalse($summaries[$resourceKey]['functional_allowed']);
            self::assertSame('functional_denied', $summaries[$resourceKey]['data_access']['mode']);
            self::assertSame(
                'AUTHZ_PERMISSION_DENIED',
                $this->engineError(fn() => $engine->queryConstraint($subject, $resourceKey, 'inspect')),
            );
        }

        self::assertTrue($summaries['preview.b-any']['functional_allowed']);
        self::assertSame('no_effective_policy', $summaries['preview.b-any']['data_access']['mode']);
        self::assertStringContainsString('1 = 0', $this->compiledSql($engine, $subject, 'preview.b-any'));

        foreach (['preview.c-system', 'preview.d-platform'] as $resourceKey) {
            self::assertSame('tenant_actor_denied', $summaries[$resourceKey]['data_access']['mode']);
            self::assertSame(
                'AUTHZ_SYSTEM_ACTOR_DENIED',
                $this->engineError(fn() => $engine->queryConstraint($subject, $resourceKey, 'inspect')),
            );
        }

        self::assertSame('global_reference_read', $summaries['preview.e-global']['data_access']['mode']);
        self::assertSame('1 = 1', $this->compiledSql($engine, $subject, 'preview.e-global'));

        self::assertSame('tenant_wide', $summaries['preview.f-tenant-wide']['data_access']['mode']);
        $tenantWideSql = $this->compiledSql($engine, $subject, 'preview.f-tenant-wide');
        self::assertStringContainsString('preview_record.tenant_id', $tenantWideSql);
        self::assertStringContainsString('1 = 1', $tenantWideSql);

        self::assertSame('conditional', $summaries['preview.g-conditional']['data_access']['mode']);
        $conditionalSql = $this->compiledSql($engine, $subject, 'preview.g-conditional');
        self::assertStringContainsString('preview_record.tenant_id', $conditionalSql);
        self::assertStringContainsString(' OR ', $conditionalSql);
        self::assertStringContainsString('preview_record.created_by_member_id', $conditionalSql);
        self::assertStringContainsString('EXISTS (', $conditionalSql);

        foreach (['preview.h-no-policy', 'preview.i-global-targeted'] as $resourceKey) {
            self::assertTrue($summaries[$resourceKey]['data_access']['runtime_decision_required']);
            self::assertSame(
                'AUTHZ_TARGET_CARDINALITY_INVALID',
                $this->engineError(fn() => $engine->queryConstraint($subject, $resourceKey, 'inspect')),
            );
        }
    }

    public function testPaginatesOperationsAndChangesTheDigestByPage(): void
    {
        $service = $this->service(new PdoAuditRepository($this->database));

        $first = $service->preview($this->actor, $this->subjectMemberId, new PageRequest(1, 2));
        $second = $service->preview($this->actor, $this->subjectMemberId, new PageRequest(2, 2));

        self::assertSame(['preview.a-functional-denied', 'preview.b-any'], array_column(
            $first['data']['resource_operations'],
            'resource_key',
        ));
        self::assertSame(['preview.c-system', 'preview.d-platform'], array_column(
            $second['data']['resource_operations'],
            'resource_key',
        ));
        self::assertNotSame($first['data']['snapshot_revision'], $second['data']['snapshot_revision']);
        self::assertSame(2, (int) $this->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());

        $catalogRevision = $this->catalog->registryRevision();
        $this->database->exec(<<<'SQL'
        UPDATE pa_module_installation
        SET status = 'maintenance', revision = revision + 1
        WHERE module_key = 'preview.unavailable'
        SQL);
        self::assertNotSame($catalogRevision, $this->catalog->registryRevision());
        $afterInstallationChange = $service->preview(
            $this->actor,
            $this->subjectMemberId,
            new PageRequest(1, 2),
        );
        self::assertNotSame(
            $first['data']['snapshot_revision'],
            $afterInstallationChange['data']['snapshot_revision'],
        );
    }

    public function testInactiveMemberHasNoEffectiveRolesPermissionsOrPolicyGroups(): void
    {
        $this->database->exec(
            "UPDATE pa_tenant_member SET status = 'suspended' WHERE id = {$this->subjectMemberId}",
        );

        $result = $this->service(new PdoAuditRepository($this->database))->preview(
            $this->actor,
            $this->subjectMemberId,
            new PageRequest(1, 100),
        );

        self::assertFalse($result['data']['member']['effective']);
        self::assertSame([], $result['data']['roles']);
        self::assertSame([], $result['data']['permission_keys']);
        foreach ($result['data']['resource_operations'] as $operation) {
            self::assertFalse($operation['functional_allowed']);
            self::assertSame('functional_denied', $operation['data_access']['mode']);
            self::assertSame([], $operation['data_access']['groups']);
        }
    }

    public function testUnknownOrCrossTenantMemberFailsWithoutAudit(): void
    {
        $otherTenant = $this->tenant('preview-beta');
        $otherMember = $this->member($otherTenant, $this->account('Other tenant member'), 'Other');
        $service = $this->service(new PdoAuditRepository($this->database));

        foreach ([$otherMember, PHP_INT_MAX] as $memberId) {
            try {
                $service->preview($this->actor, $memberId, new PageRequest());
                self::fail('A member outside the current Tenant must not be previewed.');
            } catch (AdminAccessException $exception) {
                self::assertSame('RESOURCE_NOT_FOUND', $exception->errorCode);
            }
        }

        self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
    }

    public function testAuditFailureRollsBackAndFailsTheRequest(): void
    {
        $service = $this->service(new FailingAuditRepository());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('audit unavailable');
        $service->preview($this->actor, $this->subjectMemberId, new PageRequest());
    }

    private function service(AuditRepository $audit): EffectiveAccessPreviewService
    {
        return new EffectiveAccessPreviewService(
            $this->database,
            $this->authorization,
            $this->catalog,
            $this->policies,
            $audit,
            new PreviewClock(),
        );
    }

    private function engine(): DataPermissionEngine
    {
        $provider = new StandardResourcePolicyProvider(
            new ProviderColumnMap(
                new ColumnReference('preview_record.tenant_id'),
                new ColumnReference('preview_record.created_by_member_id'),
                new ColumnReference('preview_record.department_id'),
                ['preview.project' => new ColumnReference('preview_record.project_id')],
            ),
            new PdoDepartmentHierarchyProvider($this->database),
            new PdoTargetSetMembershipProvider($this->database),
            new ConditionProviderRegistry(),
        );
        $providers = new ResourceProviderRegistry();
        $providers->registerQuery('hidden.provider', $provider);

        return new DataPermissionEngine(
            $this->catalog,
            $this->policies,
            new PolicyCache(),
            new TenantAuthorizationEvaluator($this->authorization, new RevisionPermissionCache()),
            $providers,
            new TargetResolverRegistry(),
            new TargetCatalogProviderRegistry(),
            new SharedMasterScopeProviderRegistry(),
        );
    }

    private function compiledSql(
        DataPermissionEngine $engine,
        TenantContext $subject,
        string $resourceKey,
    ): string {
        return (new PdoQueryConstraintCompiler())->compile(
            $engine->queryConstraint($subject, $resourceKey, 'inspect'),
        )->sql;
    }

    private function engineError(callable $operation): string
    {
        try {
            $operation();
            self::fail('The real data-permission engine was expected to deny this operation.');
        } catch (DataAuthorizationException $exception) {
            return $exception->errorCode;
        }
    }

    private function seedOperations(): void
    {
        $definitions = [
            ['preview.a-functional-denied', 'tenant_owned', 'rule_filtered', 'none', 'all', ['core.member.read', 'core.member.update']],
            ['preview.b-any', 'tenant_owned', 'rule_filtered', 'none', 'any', ['core.member.read', 'core.member.update']],
            ['preview.c-system', 'tenant_owned', 'system_internal', 'none', 'all', ['core.member.read']],
            ['preview.d-platform', 'platform_internal', 'tenant_wide', 'none', 'all', ['core.member.read']],
            ['preview.e-global', 'global_reference', 'global_reference_read', 'none', 'all', ['core.member.read']],
            ['preview.f-tenant-wide', 'tenant_owned', 'tenant_wide', 'none', 'all', ['core.member.read']],
            ['preview.g-conditional', 'tenant_owned', 'rule_filtered', 'none', 'all', ['core.member.read']],
            ['preview.h-no-policy', 'business_target_owned', 'explicit_targets', 'one_required', 'all', ['core.member.read']],
            ['preview.i-global-targeted', 'global_reference', 'global_reference_read', 'one_required', 'all', ['core.member.read']],
            ['preview.j-unbound', 'tenant_owned', 'rule_filtered', 'none', 'all', []],
        ];
        foreach ($definitions as [$resourceKey, $ownership, $accessMode, $cardinality, $match, $permissions]) {
            $resourceId = $this->insert('pa_protected_resource', [
                'key' => $resourceKey,
                'module_key' => 'core',
                'name' => $resourceKey,
                'ownership' => $ownership,
                'provider_key' => 'hidden.provider',
                'status' => 'active',
                'manifest_version' => '1.0.0',
                'manifest_digest' => hash('sha256', $resourceKey),
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
            $operationId = $this->insert('pa_resource_operation', [
                'protected_resource_id' => $resourceId,
                'operation' => 'inspect',
                'access_mode' => $accessMode,
                'target_cardinality' => $cardinality,
                'permission_match' => $match,
                'audit_level' => 'deny',
                'status' => 'active',
                'manifest_digest' => hash('sha256', $resourceKey . ':inspect'),
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
            foreach ($permissions as $sortOrder => $permissionKey) {
                $this->insert('pa_resource_operation_permission', [
                    'resource_operation_id' => $operationId,
                    'permission_id' => $this->permissionId($permissionKey),
                    'sort_order' => $sortOrder,
                ]);
            }
        }

        $this->insert('pa_module_installation', [
            'module_key' => 'preview.unavailable',
            'installed_version' => '1.0.0',
            'manifest_schema_version' => 1,
            'manifest_digest' => hash('sha256', 'preview.unavailable'),
            'status' => 'active',
            'installed_at' => self::NOW,
            'activated_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_tenant_module', [
            'tenant_id' => $this->tenantId,
            'module_key' => 'preview.unavailable',
            'status' => 'disabled',
            'source' => 'manual',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $resourceId = $this->insert('pa_protected_resource', [
            'key' => 'preview.z-unavailable',
            'module_key' => 'preview.unavailable',
            'name' => 'Unavailable',
            'ownership' => 'tenant_owned',
            'provider_key' => 'hidden.provider',
            'status' => 'active',
            'manifest_version' => '1.0.0',
            'manifest_digest' => hash('sha256', 'preview.z-unavailable'),
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $operationId = $this->insert('pa_resource_operation', [
            'protected_resource_id' => $resourceId,
            'operation' => 'inspect',
            'access_mode' => 'rule_filtered',
            'target_cardinality' => 'none',
            'permission_match' => 'all',
            'audit_level' => 'deny',
            'status' => 'active',
            'manifest_digest' => hash('sha256', 'preview.z-unavailable:inspect'),
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_resource_operation_permission', [
            'resource_operation_id' => $operationId,
            'permission_id' => $this->permissionId('core.member.read'),
            'sort_order' => 0,
        ]);
    }

    private function seedConditionalPolicies(): void
    {
        $operation = $this->operation('preview.g-conditional');
        $specified = $this->conditionId('core.specified_objects');
        $self = $this->conditionId('core.self');
        $this->insert('pa_resource_operation_condition', [
            'resource_operation_id' => $operation['operation_id'],
            'condition_definition_id' => $specified,
            'selector_resource_key' => 'preview.project',
            'status' => 'active',
        ]);
        $this->insert('pa_resource_operation_condition', [
            'resource_operation_id' => $operation['operation_id'],
            'condition_definition_id' => $self,
            'selector_resource_key' => null,
            'status' => 'active',
        ]);

        $policyA = $this->policy($this->roleA, $operation);
        $groupA = $this->group($policyA, 'Selected projects', 0);
        $targetSet = $this->insert('pa_data_permission_target_set', [
            'tenant_id' => $this->tenantId,
            'name' => 'Large project set',
            'target_mode' => 'resource',
            'target_resource_key' => 'preview.project',
            'status' => 'active',
            'created_by_member_id' => $this->actorMemberId,
            'updated_by_member_id' => $this->actorMemberId,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        for ($target = 1; $target <= 501; $target++) {
            $this->insert('pa_data_permission_target', [
                'tenant_id' => $this->tenantId,
                'target_set_id' => $targetSet,
                'target_id' => (string) $target,
                'status' => 'active',
                'added_by_member_id' => $this->actorMemberId,
                'added_at' => self::NOW,
            ]);
        }
        $this->condition($groupA, $specified, $targetSet);

        $policyB = $this->policy($this->roleB, $operation);
        $groupB = $this->group($policyB, 'Self', 1);
        $this->condition($groupB, $self, null);

        foreach ([
            ['tenant.preview-future', 'active', '2099-01-01 00:00:00.000', null, 'active'],
            ['tenant.preview-expired', 'active', null, '2020-01-01 00:00:00.000', 'active'],
            ['tenant.preview-policy-disabled', 'disabled', null, null, 'active'],
            ['tenant.preview-group-disabled', 'active', null, null, 'disabled'],
        ] as [$key, $policyStatus, $validFrom, $validUntil, $groupStatus]) {
            $roleId = $this->role($key, $key);
            $this->assignRole($roleId);
            $policyId = $this->policy($roleId, $operation, $policyStatus, $validFrom, $validUntil);
            $groupId = $this->group($policyId, $key, 2, $groupStatus);
            $this->condition($groupId, $self, null);
        }
    }

    /** @return array{resource_id: int, operation_id: int} */
    private function operation(string $resourceKey): array
    {
        $statement = $this->database->prepare(<<<'SQL'
SELECT resource.id AS resource_id, operation_row.id AS operation_id
FROM pa_protected_resource resource
JOIN pa_resource_operation operation_row ON operation_row.protected_resource_id = resource.id
WHERE resource.`key` = :resource_key AND operation_row.operation = 'inspect'
SQL);
        $statement->execute(['resource_key' => $resourceKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return ['resource_id' => (int) $row['resource_id'], 'operation_id' => (int) $row['operation_id']];
    }

    /** @param array{resource_id: int, operation_id: int} $operation */
    private function policy(
        int $roleId,
        array $operation,
        string $status = 'active',
        ?string $validFrom = null,
        ?string $validUntil = null,
    ): int {
        return $this->insert('pa_data_permission_policy', [
            'tenant_id' => $this->tenantId,
            'role_id' => $roleId,
            'protected_resource_id' => $operation['resource_id'],
            'resource_operation_id' => $operation['operation_id'],
            'status' => $status,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'reason' => null,
            'created_by_member_id' => $this->actorMemberId,
            'updated_by_member_id' => $this->actorMemberId,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function group(int $policyId, string $name, int $sortOrder, string $status = 'active'): int
    {
        return $this->insert('pa_data_permission_group', [
            'tenant_id' => $this->tenantId,
            'data_permission_policy_id' => $policyId,
            'name' => $name,
            'match_mode' => 'all',
            'sort_order' => $sortOrder,
            'status' => $status,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function condition(int $groupId, int $definitionId, ?int $targetSetId): void
    {
        $this->insert('pa_data_permission_condition', [
            'tenant_id' => $this->tenantId,
            'data_permission_group_id' => $groupId,
            'condition_definition_id' => $definitionId,
            'target_set_id' => $targetSetId,
            'config_json' => null,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function tenant(string $code): int
    {
        return $this->insert('pa_tenant', [
            'code' => $code,
            'name' => $code,
            'display_name' => $code,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function account(string $name): int
    {
        return $this->insert('pa_account', [
            'display_name' => $name,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function member(int $tenantId, int $accountId, string $name): int
    {
        return $this->insert('pa_tenant_member', [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'display_name' => $name,
            'status' => 'active',
            'joined_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function role(string $key, string $name, string $status = 'active'): int
    {
        return $this->insert('pa_role', [
            'tenant_id' => $this->tenantId,
            'key' => $key,
            'name' => $name,
            'status' => $status,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function assignRole(int $roleId): void
    {
        $this->insert('pa_member_role', [
            'tenant_id' => $this->tenantId,
            'tenant_member_id' => $this->subjectMemberId,
            'role_id' => $roleId,
            'assigned_at' => self::NOW,
        ]);
    }

    private function grant(int $roleId, string $permissionKey): void
    {
        $this->insert('pa_role_permission', [
            'tenant_id' => $this->tenantId,
            'role_id' => $roleId,
            'permission_id' => $this->permissionId($permissionKey),
            'granted_at' => self::NOW,
        ]);
    }

    private function permissionId(string $key): int
    {
        $statement = $this->database->prepare('SELECT id FROM pa_permission WHERE `key` = :key');
        $statement->execute(['key' => $key]);

        return (int) $statement->fetchColumn();
    }

    private function conditionId(string $key): int
    {
        $statement = $this->database->prepare('SELECT id FROM pa_data_condition_definition WHERE `key` = :key');
        $statement->execute(['key' => $key]);

        return (int) $statement->fetchColumn();
    }
}

final class PreviewClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-19T08:00:00Z');
    }
}

final class FailingAuditRepository implements AuditRepository
{
    public function appendPlatform(
        string $eventType,
        string $action,
        string $requestId,
        ?int $operatorId,
        ?int $accountId,
        array $metadata = [],
    ): void {}

    public function appendTenantSystem(
        int $tenantId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata = [],
    ): void {}

    public function appendTenantMember(
        TenantContext $context,
        string $eventType,
        string $action,
        ?string $targetResourceType = null,
        ?string $targetResourceId = null,
        ?string $boundaryTargetType = null,
        ?string $boundaryTargetId = null,
        int $targetCount = 0,
        ?string $targetSetDigest = null,
        array $metadata = [],
    ): void {
        throw new RuntimeException('audit unavailable');
    }

    public function appendTenantPlatformOperator(
        int $tenantId,
        int $operatorId,
        int $accountId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata = [],
    ): void {}
}
