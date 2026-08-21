<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Security;

use DateTimeImmutable;
use PDO;
use PDOException;
use PeanutAdmin\DataPermission\Constraint\PdoQueryConstraintCompiler;
use PeanutAdmin\DataPermission\Engine\DataPermissionEngine;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\DataPermission\Target\TargetCatalogQuery;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PeanutAdmin\DataPermission\Tests\Integration\Schema\DataPermissionMigrationRunner;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;
use PeanutAdmin\Testing\Authorization\AuthorizationAcceptanceEnvironment;
use PeanutAdmin\Testing\Authorization\AuthorizationAcceptanceFixture;
use PeanutAdmin\Testing\Authorization\ResourceProviderContractHarness;
use ReflectionMethod;
use ReflectionNamedType;

require_once dirname(__DIR__, 3) . '/kernel/tests/Integration/Schema/DatabaseTestCase.php';
require_once dirname(__DIR__) . '/Integration/Schema/DataPermissionMigrationRunner.php';

final class AuthorizationPathParityTest extends DatabaseTestCase
{
    private AuthorizationAcceptanceEnvironment $fixture;

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
        $this->fixture = AuthorizationAcceptanceFixture::install($this->database);
    }

    public function testListDetailSearchAndAggregateApplyAuthorizationInsideSql(): void
    {
        $rows = $this->fixture->harness->list();
        self::assertSame(['A', 'A', 'B'], array_column($rows, 'project_id'));
        self::assertStringContainsString('record.tenant_id', $this->fixture->trace->lastSql());
        self::assertStringContainsString('record.project_id', $this->fixture->trace->lastSql());

        $projectB = ResourceProviderContractHarness::targets('fixture.project', ['B']);
        self::assertSame(['B'], array_column($this->fixture->harness->list('list', $projectB), 'project_id'));
        self::assertCount(2, $this->fixture->harness->list('list', null, 'Alpha A'));
        self::assertCount(3, $this->fixture->harness->list('aggregate'));

        self::assertSame('B', $this->fixture->harness->detail(
            $this->fixture->recordIds['alpha_b'],
            'detail',
            $projectB,
        )['project_id'] ?? null);
        self::assertNull($this->fixture->harness->detail(
            $this->fixture->recordIds['beta_a'],
            'detail',
            ResourceProviderContractHarness::targets('fixture.project', ['A']),
        ));
    }

    public function testCreateUpdateAndDeleteIgnoreClientTenantAndDenyOtherScopes(): void
    {
        $projectA = ResourceProviderContractHarness::targets('fixture.project', ['A']);
        $recordId = $this->fixture->harness->create('create', $projectA, [
            'name' => 'Created in Alpha',
            'tenant_id' => $this->fixture->betaTenantId,
        ]);
        $createdTenant = $this->query("SELECT tenant_id FROM fixture_record WHERE id = {$recordId}")->fetchColumn();
        self::assertSame($this->fixture->alphaTenantId, (int) $createdTenant);

        $this->expectDataError('AUTHZ_DATA_DENIED', fn() => $this->fixture->harness->create(
            'create',
            ResourceProviderContractHarness::targets('fixture.project', ['B']),
            ['name' => 'Denied B'],
        ));
        self::assertTrue($this->fixture->harness->update(
            $this->fixture->recordIds['alpha_a_1'],
            'update',
            $projectA,
            'Updated A',
        ));
        $this->expectDataError('AUTHZ_DATA_DENIED', fn() => $this->fixture->harness->update(
            $this->fixture->recordIds['alpha_b'],
            'update',
            ResourceProviderContractHarness::targets('fixture.project', ['B']),
            'Should not update B',
        ));
        $this->expectDataError('AUTHZ_DATA_DENIED', fn() => $this->fixture->harness->delete(
            $this->fixture->recordIds['beta_a'],
            'delete',
            $projectA,
        ));
        self::assertTrue($this->fixture->harness->delete(
            $this->fixture->recordIds['alpha_a_2'],
            'delete',
            $projectA,
        ));
    }

    public function testBatchAndImportAreAtomicAndDoNotTreatMultipleProjectsAsOneTarget(): void
    {
        $projectA = ResourceProviderContractHarness::targets('fixture.project', ['A']);
        self::assertSame(2, $this->fixture->harness->batchUpdate(
            [
                $this->fixture->recordIds['alpha_a_1'],
                $this->fixture->recordIds['alpha_a_2'],
            ],
            'batch',
            $projectA,
            'Batch A',
        ));

        $beforeB = $this->recordName($this->fixture->recordIds['alpha_b']);
        $this->expectDataError('AUTHZ_DATA_DENIED', fn() => $this->fixture->harness->batchUpdate(
            [
                $this->fixture->recordIds['alpha_a_1'],
                $this->fixture->recordIds['alpha_b'],
            ],
            'batch',
            $projectA,
            'Cross project',
        ));
        self::assertSame($beforeB, $this->recordName($this->fixture->recordIds['alpha_b']));

        $beforeCount = (int) $this->query('SELECT COUNT(*) FROM fixture_record')->fetchColumn();
        $this->expectDataError('AUTHZ_DATA_DENIED', fn() => $this->fixture->harness->import('import', [
            ['name' => 'Import A', 'project_id' => 'A', 'tenant_id' => $this->fixture->betaTenantId],
            ['name' => 'Import B denied', 'project_id' => 'B'],
        ]));
        self::assertSame($beforeCount, (int) $this->query('SELECT COUNT(*) FROM fixture_record')->fetchColumn());
        self::assertSame(1, $this->fixture->harness->import('import', [[
            'name' => 'Import A allowed',
            'project_id' => 'A',
            'tenant_id' => $this->fixture->betaTenantId,
        ]]));
        self::assertSame($this->fixture->alphaTenantId, (int) $this->query(<<<'SQL'
SELECT tenant_id FROM fixture_record WHERE name = 'Import A allowed'
SQL)->fetchColumn());
    }

    public function testExportAndJobContractsRevalidateAtExecutionTime(): void
    {
        $export = $this->fixture->harness->exportContract('export');
        $job = $this->fixture->harness->jobContract('job');
        self::assertCount(3, $export->execute());
        self::assertCount(3, $job->execute());

        foreach (['export', 'job'] as $operation) {
            $policyId = $this->fixture->policyIds[$operation];
            $this->database->exec(<<<SQL
UPDATE pa_data_permission_policy
SET status = 'disabled', revision = revision + 1
WHERE id = {$policyId}
SQL);
        }

        self::assertSame([], $export->execute());
        self::assertSame([], $job->execute());
    }

    public function testTypedTargetsCardinalityBulkAndPolicyPublishFailClosed(): void
    {
        $this->expectDataError('AUTHZ_TARGET_TYPE_MISMATCH', fn() => $this->fixture->engine->decideTargets(
            $this->fixture->alphaContext,
            'fixture.record',
            'update',
            ResourceProviderContractHarness::targets('fixture.queue', ['A']),
        ));
        $this->expectDataError('AUTHZ_TARGET_CARDINALITY_INVALID', fn() => $this->fixture->engine->decideTargets(
            $this->fixture->alphaContext,
            'fixture.record',
            'update',
            new TypedResourceTargetCollection(),
        ));
        $this->expectDataError('AUTHZ_TARGET_CARDINALITY_INVALID', fn() => $this->fixture->engine->decideTargets(
            $this->fixture->alphaContext,
            'fixture.record',
            'update',
            ResourceProviderContractHarness::targets('fixture.project', ['A', 'B']),
        ));
        $this->expectDataError('AUTHZ_TARGET_CARDINALITY_INVALID', fn() => $this->fixture->engine->decideTargets(
            $this->fixture->alphaContext,
            'fixture.record',
            'bulk-write',
            ResourceProviderContractHarness::targets('fixture.project', ['A']),
        ));

        $before = (int) $this->query('SELECT COUNT(*) FROM fixture_record')->fetchColumn();
        self::assertFalse($this->fixture->engine->decideTargets(
            $this->fixture->alphaContext,
            'fixture.record',
            'policy-publish',
            ResourceProviderContractHarness::targets('fixture.project', ['A', 'B']),
        )->allowed);
        self::assertSame($before, (int) $this->query('SELECT COUNT(*) FROM fixture_record')->fetchColumn());

        $typed = new TypedResourceTargetSet('fixture.project', ['A']);
        self::assertSame('fixture.project', $typed->targetResourceKey);
        self::assertSame(['A'], $typed->targetIds);
    }

    public function testTargetCandidatesArePagedAndPolicyConfigurationNeedsDelegationPermission(): void
    {
        $page = $this->fixture->engine->searchAllowedTargets(
            $this->fixture->alphaContext,
            'fixture.record',
            'list',
            new TargetCatalogQuery('fixture.project', '', 1, 1),
        );
        self::assertSame(2, $page->total);
        self::assertCount(1, $page->items);
        self::assertSame('A', $page->items[0]['id']);

        $this->expectDataError('AUTHZ_PERMISSION_DENIED', fn() => $this->fixture->engine->searchAllowedTargets(
            $this->fixture->alphaContext,
            'fixture.record',
            'list',
            new TargetCatalogQuery('fixture.project', '', 1, 20, 'primary', 'policy-config'),
        ));
    }

    public function testSharedMasterVisibilityUsageAndMissingProviderAreFailClosed(): void
    {
        $compiled = (new PdoQueryConstraintCompiler())->compile($this->fixture->engine->queryConstraint(
            $this->fixture->alphaContext,
            'fixture.reference',
            'list',
        ));
        $statement = $this->database->prepare(
            'SELECT id FROM fixture_reference reference WHERE ' . $compiled->sql . ' ORDER BY id',
        );
        $statement->execute($compiled->parameters);
        self::assertSame(['PRIVATE_A', 'PUBLIC'], $statement->fetchAll(PDO::FETCH_COLUMN));

        $targeted = (new PdoQueryConstraintCompiler())->compile($this->fixture->engine->queryConstraint(
            $this->fixture->alphaContext,
            'fixture.reference',
            'list',
            ResourceProviderContractHarness::targets('fixture.project', ['A']),
        ));
        $statement = $this->database->prepare(
            'SELECT id FROM fixture_reference reference WHERE ' . $targeted->sql . ' ORDER BY id',
        );
        $statement->execute($targeted->parameters);
        self::assertSame(['PRIVATE_A', 'PUBLIC'], $statement->fetchAll(PDO::FETCH_COLUMN));

        self::assertTrue($this->fixture->engine->decideTargets(
            $this->fixture->alphaContext,
            'fixture.reference',
            'use',
            ResourceProviderContractHarness::targets('fixture.project', ['A']),
            'PRIVATE_A',
        )->allowed);
        self::assertFalse($this->fixture->engine->decideTargets(
            $this->fixture->alphaContext,
            'fixture.reference',
            'use',
            ResourceProviderContractHarness::targets('fixture.project', ['B']),
            'PRIVATE_A',
        )->allowed);
        $this->expectDataError('AUTHZ_PROVIDER_MISSING', fn() => $this->fixture->engine->queryConstraint(
            $this->fixture->alphaContext,
            'fixture.reference-unregistered',
            'list',
        ));
    }

    public function testTenantStatusMultiTenantAccountSentinelsAndContextTypeCannotBypass(): void
    {
        self::assertSame(['A', 'A', 'B'], array_column($this->fixture->harness->list(), 'project_id'));
        $this->expectDataError('AUTHZ_PERMISSION_DENIED', fn() => $this->fixture->engine->queryConstraint(
            $this->fixture->betaContext,
            'fixture.record',
            'list',
        ));

        $statement = $this->database->prepare(<<<'SQL'
INSERT INTO fixture_record (tenant_id, project_id, created_by_member_id, code, name)
VALUES (:tenant_id, 'A', :member_id, 'shared-code', :name)
SQL);
        $statement->execute([
            'tenant_id' => $this->fixture->alphaTenantId,
            'member_id' => $this->fixture->alphaMemberId,
            'name' => 'Alpha shared code',
        ]);
        $statement->execute([
            'tenant_id' => $this->fixture->betaTenantId,
            'member_id' => $this->fixture->betaMemberId,
            'name' => 'Beta shared code',
        ]);
        try {
            $statement->execute([
                'tenant_id' => $this->fixture->alphaTenantId,
                'member_id' => $this->fixture->alphaMemberId,
                'name' => 'Alpha duplicate code',
            ]);
            self::fail('Expected the tenant-local unique code to reject a duplicate.');
        } catch (PDOException) {
            self::addToAssertionCount(1);
        }

        $this->database->exec(<<<SQL
UPDATE pa_tenant
SET status = 'suspended', security_revision = security_revision + 1
WHERE id = {$this->fixture->alphaTenantId}
SQL);
        $this->expectDataError('AUTHZ_PERMISSION_DENIED', fn() => $this->fixture->harness->list());

        $sentinel = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            0,
            'sentinel',
            0,
            $this->fixture->accountId,
            0,
            'web',
            new DateTimeImmutable('2026-07-16T12:00:00Z'),
            1,
        ), 'request-sentinel');
        $this->expectDataError('AUTHZ_MODULE_UNAVAILABLE', fn() => $this->fixture->engine->queryConstraint(
            $sentinel,
            'fixture.record',
            'list',
        ));

        $parameter = (new ReflectionMethod(DataPermissionEngine::class, 'queryConstraint'))->getParameters()[0];
        $type = $parameter->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(TenantContext::class, $type->getName());
        self::assertFalse($type->allowsNull());
    }

    public function testMultiTargetAuditRecordsActorBoundaryCountAndDigestWithoutRawIds(): void
    {
        $this->fixture->harness->batchUpdate(
            [
                $this->fixture->recordIds['alpha_a_1'],
                $this->fixture->recordIds['alpha_a_2'],
            ],
            'batch',
            ResourceProviderContractHarness::targets('fixture.project', ['A']),
            'Audited batch',
        );
        $event = $this->query(<<<'SQL'
SELECT tenant_id, actor_tenant_id, actor_tenant_member_id, actor_account_id,
       actor_type, target_count, target_set_digest,
       authorization_basis_json, metadata_json
FROM pa_tenant_audit_event WHERE event_type = 'fixture.batch.updated'
SQL)->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($event);
        self::assertSame($this->fixture->alphaTenantId, (int) $event['tenant_id']);
        self::assertSame($this->fixture->alphaTenantId, (int) $event['actor_tenant_id']);
        self::assertSame($this->fixture->alphaMemberId, (int) $event['actor_tenant_member_id']);
        self::assertSame($this->fixture->accountId, (int) $event['actor_account_id']);
        self::assertSame('member', $event['actor_type']);
        self::assertSame(2, (int) $event['target_count']);
        self::assertSame(64, strlen((string) $event['target_set_digest']));
        self::assertStringContainsString('tenant', (string) $event['authorization_basis_json']);
        self::assertStringContainsString('false', (string) $event['metadata_json']);
        self::assertStringNotContainsString((string) $this->fixture->recordIds['alpha_a_1'], (string) $event['metadata_json']);
    }

    private function recordName(int $recordId): string
    {
        return (string) $this->query("SELECT name FROM fixture_record WHERE id = {$recordId}")->fetchColumn();
    }

    private function expectDataError(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected data authorization error {$code}.");
        } catch (DataAuthorizationException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}
