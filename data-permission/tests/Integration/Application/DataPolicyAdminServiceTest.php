<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Integration\Application;

use DateTimeImmutable;
use PeanutAdmin\DataPermission\Application\DataPolicyAdminService;
use PeanutAdmin\DataPermission\Target\ResolvedResourceTargets;
use PeanutAdmin\DataPermission\Target\ResourceTargetResolver;
use PeanutAdmin\DataPermission\Target\TargetResolverRegistry;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PeanutAdmin\DataPermission\Tests\Integration\Schema\DataPermissionMigrationRunner;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Kernel\Authorization\Persistence\PdoAuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__, 4) . '/kernel/tests/Integration/Schema/DatabaseTestCase.php';
require_once dirname(__DIR__) . '/Schema/DataPermissionMigrationRunner.php';

final class DataPolicyAdminServiceTest extends DatabaseTestCase
{
    private const NOW = '2026-07-17 07:00:00.000';

    private DataPolicyAdminService $service;
    private TenantContext $context;
    private int $roleId;

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
        $tenantId = $this->tenant();
        $accountId = $this->account();
        $memberId = $this->member($tenantId, $accountId);
        $this->roleId = $this->role($tenantId, 'tenant.reviewer');
        $this->catalog($tenantId);
        $registry = new TargetResolverRegistry();
        $registry->register('test.project-resolver', new TestProjectResolver());
        $this->service = new DataPolicyAdminService($this->database, $registry);
        $this->context = TenantContext::fromValidatedSession(
            new ValidatedTenantSession(
                1,
                '01J00000000000000000000000',
                $tenantId,
                $accountId,
                $memberId,
                'admin-web',
                new DateTimeImmutable('2026-07-17T07:00:00Z'),
                1,
            ),
            'req_policy_test',
        );
    }

    public function testReplacesAndReadsTypedPolicyAtomicallyWithRevisionAndAudit(): void
    {
        $policy = $this->service->replace(
            $this->context,
            $this->roleId,
            'example.work-item',
            'list',
            [
                'status' => 'active',
                'reason' => 'Review own tenant and selected projects',
                'groups' => [
                    [
                        'name' => 'Tenant access',
                        'conditions' => [['condition_key' => 'core.tenant_all']],
                    ],
                    [
                        'name' => 'Selected projects',
                        'conditions' => [[
                            'condition_key' => 'core.specified_objects',
                            'target_set' => [
                                'name' => 'Priority projects',
                                'target_resource_key' => 'example.project',
                                'targets' => [['target_id' => '9001'], ['target_id' => '9002']],
                            ],
                        ]],
                    ],
                ],
            ],
            null,
        );

        self::assertSame('1', $policy['revision']);
        self::assertSame(['9001', '9002'], array_column(
            $policy['groups'][1]['conditions'][0]['target_set']['targets'],
            'target_id',
        ));
        self::assertSame($policy, $this->service->get(
            $this->context->tenantId,
            $this->roleId,
            'example.work-item',
            'list',
        ));
        $this->expectAdminError('PRECONDITION_REQUIRED', fn(): array => $this->service->replace(
            $this->context,
            $this->roleId,
            'example.work-item',
            'list',
            ['status' => 'disabled', 'groups' => []],
            null,
        ));
        $this->expectAdminError('REVISION_MISMATCH', fn(): array => $this->service->replace(
            $this->context,
            $this->roleId,
            'example.work-item',
            'list',
            ['status' => 'disabled', 'groups' => []],
            99,
        ));

        $policy = $this->service->replace(
            $this->context,
            $this->roleId,
            'example.work-item',
            'list',
            [
                'status' => 'active',
                'reason' => 'Narrow selected project',
                'groups' => [[
                    'name' => 'Selected projects',
                    'conditions' => [[
                        'condition_key' => 'core.specified_objects',
                        'target_set' => [
                            'name' => 'Priority project',
                            'target_resource_key' => 'example.project',
                            'targets' => [['target_id' => '9002']],
                        ],
                    ]],
                ]],
            ],
            1,
        );

        self::assertSame('2', $policy['revision']);
        self::assertSame(['9002'], array_column(
            $policy['groups'][0]['conditions'][0]['target_set']['targets'],
            'target_id',
        ));
        self::assertSame(1, (int) $this->query('SELECT COUNT(*) FROM pa_data_permission_target')->fetchColumn());
        self::assertSame(2, (int) $this->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
        self::assertSame(3, (int) $this->query(
            'SELECT authorization_revision FROM pa_role WHERE id = ' . $this->roleId,
        )->fetchColumn());
    }

    public function testRejectsUnknownConditionsConfigAndTargetsWithoutPartialWrites(): void
    {
        foreach ([
            [[
                'status' => 'active',
                'groups' => [[
                    'name' => 'Unknown',
                    'conditions' => [['condition_key' => 'core.unknown']],
                ]],
            ], 'DATA_POLICY_CONDITION_INVALID'],
            [[
                'status' => 'active',
                'groups' => [[
                    'name' => 'Config injection',
                    'conditions' => [[
                        'condition_key' => 'core.tenant_all',
                        'config' => ['sql' => '1=1'],
                    ]],
                ]],
            ], 'DATA_POLICY_CONFIG_INVALID'],
            [[
                'status' => 'active',
                'groups' => [[
                    'name' => 'Missing target',
                    'conditions' => [[
                        'condition_key' => 'core.specified_objects',
                        'target_set' => [
                            'name' => 'Missing',
                            'target_resource_key' => 'example.project',
                            'targets' => [['target_id' => '9999']],
                        ],
                    ]],
                ]],
            ], 'AUTHZ_TARGET_NOT_FOUND'],
        ] as [$payload, $errorCode]) {
            $this->expectAdminError($errorCode, fn(): array => $this->service->replace(
                $this->context,
                $this->roleId,
                'example.work-item',
                'list',
                $payload,
                null,
            ));
        }

        self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM pa_data_permission_policy')->fetchColumn());
        self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
    }

    private function catalog(int $tenantId): void
    {
        $this->insert('pa_module_installation', [
            'module_key' => 'example.work-item',
            'installed_version' => '1.0.0',
            'manifest_schema_version' => 1,
            'manifest_digest' => hash('sha256', 'example.work-item'),
            'status' => 'active',
            'installed_at' => self::NOW,
            'activated_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_module_installation', [
            'module_key' => 'example.target',
            'installed_version' => '1.0.0',
            'manifest_schema_version' => 1,
            'manifest_digest' => hash('sha256', 'example.target'),
            'status' => 'active',
            'installed_at' => self::NOW,
            'activated_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        foreach (['example.work-item', 'example.target'] as $moduleKey) {
            $this->insert('pa_tenant_module', [
                'tenant_id' => $tenantId,
                'module_key' => $moduleKey,
                'status' => 'enabled',
                'source' => 'manual',
                'enabled_at' => self::NOW,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
        }
        $resourceId = $this->insert('pa_protected_resource', [
            'key' => 'example.work-item',
            'module_key' => 'example.work-item',
            'name' => 'Work Item',
            'ownership' => 'business_target_owned',
            'provider_key' => 'test.work-item-provider',
            'status' => 'active',
            'manifest_version' => '1.0.0',
            'manifest_digest' => hash('sha256', 'work-item-resource'),
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $operationId = $this->insert('pa_resource_operation', [
            'protected_resource_id' => $resourceId,
            'operation' => 'list',
            'access_mode' => 'rule_filtered',
            'target_cardinality' => 'many_readable',
            'permission_match' => 'all',
            'audit_level' => 'deny',
            'status' => 'active',
            'manifest_digest' => hash('sha256', 'work-item-list'),
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $targetTypeId = $this->insert('pa_target_type', [
            'key' => 'example.project',
            'module_key' => 'example.target',
            'name' => 'Project',
            'resolver_key' => 'test.project-resolver',
            'catalog_provider_key' => 'test.project-catalog',
            'id_format' => 'decimal',
            'status' => 'active',
            'manifest_version' => '1.0.0',
            'manifest_digest' => hash('sha256', 'project-target'),
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_resource_operation_target_type', [
            'resource_operation_id' => $operationId,
            'target_type_id' => $targetTypeId,
            'target_role' => 'primary',
            'input_mode' => 'explicit',
            'status' => 'active',
        ]);
        foreach ([
            ['core.tenant_all', null],
            ['core.specified_objects', 'example.project'],
        ] as [$conditionKey, $selector]) {
            $conditionId = (int) $this->query(
                "SELECT id FROM pa_data_condition_definition WHERE `key` = '{$conditionKey}'",
            )->fetchColumn();
            $this->insert('pa_resource_operation_condition', [
                'resource_operation_id' => $operationId,
                'condition_definition_id' => $conditionId,
                'selector_resource_key' => $selector,
                'status' => 'active',
            ]);
        }
    }

    private function tenant(): int
    {
        return $this->insert('pa_tenant', [
            'code' => 'policy-tenant',
            'name' => 'Policy Tenant',
            'display_name' => 'Policy Tenant',
            'status' => 'active',
            'activated_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function account(): int
    {
        return $this->insert('pa_account', [
            'display_name' => 'Policy Administrator',
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function member(int $tenantId, int $accountId): int
    {
        return $this->insert('pa_tenant_member', [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'display_name' => 'Policy Administrator',
            'status' => 'active',
            'joined_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function role(int $tenantId, string $key): int
    {
        return $this->insert('pa_role', [
            'tenant_id' => $tenantId,
            'key' => $key,
            'name' => 'Reviewer',
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    /** @param callable(): mixed $operation */
    private function expectAdminError(string $code, callable $operation): void
    {
        try {
            $operation();
        } catch (AdminAccessException|ModuleException $exception) {
            self::assertSame($code, $exception->errorCode);

            return;
        }

        self::fail("Expected {$code}.");
    }
}

final class TestProjectResolver implements ResourceTargetResolver
{
    public function resolveAndValidate(TenantContext $context, TypedResourceTargetSet $targets): ResolvedResourceTargets
    {
        if ($targets->targetResourceKey !== 'example.project'
            || array_diff($targets->targetIds, ['9001', '9002']) !== []) {
            throw new ModuleException('AUTHZ_TARGET_NOT_FOUND', 'Project target was not found.');
        }

        return new ResolvedResourceTargets(new TypedResourceTargetCollection([$targets]));
    }
}
