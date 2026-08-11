<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Tests\Integration\Application;

use DateTimeImmutable;
use PDO;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Kernel\Idempotency\IdempotencySchema;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use PeanutAdmin\Workflow\Adapter\WorkflowAssignmentResolver;
use PeanutAdmin\Workflow\Adapter\WorkflowAttachment;
use PeanutAdmin\Workflow\Adapter\WorkflowAttachmentResolver;
use PeanutAdmin\Workflow\Adapter\WorkflowAuthorizationResolver;
use PeanutAdmin\Workflow\Adapter\WorkflowSideEffectPublisher;
use PeanutAdmin\Workflow\Adapter\WorkflowSubjectRevisionResolver;
use PeanutAdmin\Workflow\Adapter\WorkflowTransitionEffects;
use PeanutAdmin\Workflow\Application\WorkflowException;
use PeanutAdmin\Workflow\Application\WorkflowQueryService;
use PeanutAdmin\Workflow\Application\WorkflowReceipt;
use PeanutAdmin\Workflow\Application\WorkflowRuntime;
use PeanutAdmin\Workflow\Database\Schema;
use PeanutAdmin\Workflow\Definition\WorkflowGraph;
use PeanutAdmin\Workflow\Package;
use PeanutAdmin\Workflow\Tests\Unit\Definition\WorkflowGraphTest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class WorkflowRuntimeTest extends TestCase
{
    private const DATABASE = 'peanut_admin_p1_wf01_runtime_test';

    private PDO $admin;
    private PDO $pdo;

    protected function setUp(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            return;
        }
        $port = (int) (getenv('DB_PORT') ?: 0);
        if (getenv('DB_HOST') !== '127.0.0.1' || $port < 1024 || $port > 65535 || $port !== (int) getenv('MYSQL_PORT')) {
            throw new RuntimeException('Workflow qualification requires an explicit local MySQL port.');
        }
        $password = getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev';
        $this->admin = new PDO(
            "mysql:host=127.0.0.1;port={$port};charset=utf8mb4",
            'root',
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->admin->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        $this->admin->exec('CREATE DATABASE `' . self::DATABASE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->pdo = new PDO(
            "mysql:host=127.0.0.1;port={$port};dbname=" . self::DATABASE . ';charset=utf8mb4',
            'root',
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
        $this->createKernelFixtures();
        foreach (Schema::createSql() as $statement) {
            $this->pdo->exec($statement);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            $this->admin->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        }
    }

    public function testPublicCommandSurfaceAndMinimalReceiptAreStable(): void
    {
        $expectedMethods = [
            'saveDraft', 'publishDefinition', 'retireDefinition',
            'startInstance', 'applyTransition', 'applyAutomation',
        ];
        $actualMethods = array_values(array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new ReflectionClass(WorkflowRuntime::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn(ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === WorkflowRuntime::class
                    && $method->getName() !== '__construct',
            ),
        ));
        self::assertSame($expectedMethods, $actualMethods);
        self::assertSame([
            'definition', 'definitionDraft', 'definitions',
            'instance', 'workItems', 'events',
        ], array_values(array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new ReflectionClass(WorkflowQueryService::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn(ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === WorkflowQueryService::class
                    && $method->getName() !== '__construct',
            ),
        )));
        $receipt = new WorkflowReceipt(
            'workflow.apply-transition',
            7,
            3,
            'instance_' . str_repeat('a', 32),
            'active',
            'review',
            4,
            9,
            ['work_' . str_repeat('b', 32), 'work_' . str_repeat('a', 32)],
        );
        self::assertSame(
            $receipt->toArray(),
            WorkflowReceipt::fromArray($receipt->toArray(), 'workflow.apply-transition')->toArray(),
        );
        self::assertSame([
            'work_' . str_repeat('a', 32),
            'work_' . str_repeat('b', 32),
        ], $receipt->workItemKeys);

        $invalidReceipts = [];
        $wrongOperation = $receipt->toArray();
        $wrongOperation['operation'] = 'workflow.apply-automation';
        $invalidReceipts[] = [$wrongOperation, 'workflow.apply-transition'];
        $unknownField = $receipt->toArray();
        $unknownField['subject_key'] = 'hidden';
        $invalidReceipts[] = [$unknownField, 'workflow.apply-transition'];
        $unsortedKeys = $receipt->toArray();
        $unsortedKeys['work_item_keys'] = array_reverse($unsortedKeys['work_item_keys']);
        $invalidReceipts[] = [$unsortedKeys, 'workflow.apply-transition'];
        $invalidStart = $receipt->toArray();
        $invalidStart['operation'] = 'workflow.start-instance';
        $invalidStart['instance_revision'] = 2;
        $invalidReceipts[] = [$invalidStart, 'workflow.start-instance'];
        foreach ($invalidReceipts as [$invalidReceipt, $expectedOperation]) {
            try {
                WorkflowReceipt::fromArray($invalidReceipt, $expectedOperation);
                self::fail('A non-canonical or operation-inconsistent receipt must fail closed.');
            } catch (WorkflowException $exception) {
                self::assertSame('INTERNAL_ERROR', $exception->errorCode);
            }
        }
    }

    public function testQueryMasksDatabaseDetailsAsInternalError(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $context = $this->tenantContext(1, 11, 101, 'req_workflow_query');
        $query = new WorkflowQueryService($pdo, new AllowingWorkflowAuthorization($pdo));

        try {
            $query->definition($this->definitionContext($context, 'read'), 'module.sample', 'approval');
            self::fail('Missing persistence must fail closed.');
        } catch (WorkflowException $exception) {
            self::assertSame('INTERNAL_ERROR', $exception->errorCode);
            self::assertStringNotContainsString('pa_workflow_definition', $exception->getMessage());
        }
    }

    public function testDefinitionInstanceHumanAndAutomationCommandsAreAtomicAndReplaySafe(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through P1-WORKFLOW-RUNTIME-001 MySQL qualification.');
        }
        $this->pdo->exec("INSERT INTO pa_tenant VALUES ()");
        $tenantId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO pa_tenant_member (id, tenant_id, account_id, status) VALUES (11, ?, 101, 'active')")
            ->execute([$tenantId]);
        $tenantContext = $this->tenantContext($tenantId, 11, 101, 'req_workflow_runtime');
        $publisher = new RecordingWorkflowPublisher($this->pdo);
        $runtime = new WorkflowRuntime(
            $this->pdo,
            new FixedWorkflowAssignments($this->pdo, 11),
            new AllowingWorkflowAuthorization($this->pdo),
            new FixedWorkflowSubject($this->pdo),
            new EmptyWorkflowAttachments($this->pdo),
            $publisher,
        );
        $draft = $runtime->saveDraft(
            $this->definitionContext($tenantContext, 'write'),
            'module.sample',
            'approval',
            WorkflowGraphTest::validGraph(),
            null,
            'workflow-draft-key-0001',
        );
        $published = $runtime->publishDefinition(
            $this->definitionContext($tenantContext, 'publish'),
            'module.sample',
            'approval',
            1,
            'workflow-publish-key-01',
        );
        $started = $runtime->startInstance(
            $this->definitionContext($tenantContext, 'read'),
            'module.sample',
            'approval',
            'subject.item',
            'subject-1',
            'revision-1',
            [],
            'workflow-start-key-0001',
        );
        $transitioned = $runtime->applyTransition(
            $this->definitionContext($tenantContext, 'read'),
            (string) $started->instanceKey,
            'approve',
            1,
            'revision-1',
            'Approved.',
            [],
            'workflow-transition-001',
        );
        $transitionReplay = $runtime->applyTransition(
            $this->definitionContext($tenantContext, 'read'),
            (string) $started->instanceKey,
            'approve',
            1,
            'revision-1',
            'Approved.',
            [],
            'workflow-transition-001',
        );
        $completed = $runtime->applyAutomation(
            $this->definitionContext($tenantContext, 'read'),
            (string) $started->instanceKey,
            'finish',
            2,
            'revision-1',
            'job_' . str_repeat('d', 32),
        );
        $replayed = $runtime->applyAutomation(
            $this->definitionContext($tenantContext, 'read'),
            (string) $started->instanceKey,
            'finish',
            2,
            'revision-1',
            'job_' . str_repeat('d', 32),
        );

        self::assertSame($draft->definitionId, $published->definitionId);
        self::assertSame('review', $started->currentNodeKey);
        self::assertSame('action', $transitioned->currentNodeKey);
        self::assertSame($transitioned->toArray(), $transitionReplay->toArray());
        self::assertSame('completed', $completed->instanceStatus);
        self::assertSame($completed->toArray(), $replayed->toArray());
        self::assertSame(1, $publisher->publishCount);
        self::assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
        self::assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_tenant_idempotency_record')->fetchColumn());
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_workflow_event')->fetchColumn());
    }

    public function testRejectsUndeclaredAssignmentSourceBeforeInstanceWrite(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through P1-WORKFLOW-RUNTIME-001 MySQL qualification.');
        }
        [$tenantId, $context] = $this->seedTenantContext('req_workflow_assignment');
        $runtime = new WorkflowRuntime(
            $this->pdo,
            new FixedWorkflowAssignments($this->pdo, 11),
            new AllowingWorkflowAuthorization($this->pdo),
            new FixedWorkflowSubject($this->pdo),
            new EmptyWorkflowAttachments($this->pdo),
            new RecordingWorkflowPublisher($this->pdo),
        );
        $graph = WorkflowGraphTest::validGraph();
        $graph['nodes'][1]['assignments'] = [['kind' => 'department', 'key' => 'editors']];
        $runtime->saveDraft(
            $this->definitionContext($context, 'write'),
            'module.sample',
            'assignment-check',
            $graph,
            null,
            'workflow-draft-assignment',
        );
        $runtime->publishDefinition(
            $this->definitionContext($context, 'publish'),
            'module.sample',
            'assignment-check',
            1,
            'workflow-publish-assignment',
        );

        try {
            $runtime->startInstance(
                $this->definitionContext($context, 'read'),
                'module.sample',
                'assignment-check',
                'subject.item',
                'subject-assignment',
                'revision-1',
                [],
                'workflow-start-assignment',
            );
            self::fail('Undeclared assignment output must fail closed.');
        } catch (WorkflowException $exception) {
            self::assertSame('WORKFLOW_ASSIGNMENT_DENIED', $exception->errorCode);
        }

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_workflow_instance')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM pa_tenant_idempotency_record WHERE tenant_id = {$tenantId}",
        )->fetchColumn());
    }

    public function testUnexpectedPublisherFailureIsRedactedAndRollsBackTheTransition(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through P1-WORKFLOW-RUNTIME-001 MySQL qualification.');
        }
        [, $context] = $this->seedTenantContext('req_workflow_failure');
        $runtime = new WorkflowRuntime(
            $this->pdo,
            new FixedWorkflowAssignments($this->pdo, 11),
            new AllowingWorkflowAuthorization($this->pdo),
            new FixedWorkflowSubject($this->pdo),
            new EmptyWorkflowAttachments($this->pdo),
            new FailingWorkflowPublisher($this->pdo),
        );
        $runtime->saveDraft(
            $this->definitionContext($context, 'write'),
            'module.sample',
            'failure-check',
            WorkflowGraphTest::validGraph(),
            null,
            'workflow-draft-failure',
        );
        $runtime->publishDefinition(
            $this->definitionContext($context, 'publish'),
            'module.sample',
            'failure-check',
            1,
            'workflow-publish-failure',
        );
        $started = $runtime->startInstance(
            $this->definitionContext($context, 'read'),
            'module.sample',
            'failure-check',
            'subject.item',
            'subject-failure',
            'revision-1',
            [],
            'workflow-start-failure',
        );

        try {
            $runtime->applyTransition(
                $this->definitionContext($context, 'read'),
                (string) $started->instanceKey,
                'approve',
                1,
                'revision-1',
                null,
                [],
                'workflow-transition-failure',
            );
            self::fail('An unexpected provider failure must fail the transition.');
        } catch (WorkflowException $exception) {
            self::assertSame('INTERNAL_ERROR', $exception->errorCode);
            self::assertStringNotContainsString('provider-secret', $exception->getMessage());
        }

        self::assertSame(1, (int) $this->pdo->query('SELECT revision FROM pa_workflow_instance')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM pa_workflow_work_item WHERE status = 'pending'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_workflow_event')->fetchColumn());
    }

    public function testAllReviewRequiresEverySnapshotAssigneeAndWithdrawalCancels(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through P1-WORKFLOW-RUNTIME-001 MySQL qualification.');
        }
        [$tenantId, $firstContext] = $this->seedTenantContext('req_workflow_all_first');
        $this->pdo->prepare("INSERT INTO pa_tenant_member (id, tenant_id, account_id, status) VALUES (12, ?, 102, 'active')")
            ->execute([$tenantId]);
        $secondContext = $this->tenantContext($tenantId, 12, 102, 'req_workflow_all_second');
        $runtime = new WorkflowRuntime(
            $this->pdo,
            new DeclaredWorkflowAssignments($this->pdo),
            new AllowingWorkflowAuthorization($this->pdo),
            new FixedWorkflowSubject($this->pdo),
            new EmptyWorkflowAttachments($this->pdo),
            new RecordingWorkflowPublisher($this->pdo),
        );
        $graph = WorkflowGraphTest::validGraph();
        $graph['nodes'][1]['completion_policy'] = 'all';
        $graph['nodes'][1]['assignments'] = [
            ['kind' => 'member', 'key' => '11'],
            ['kind' => 'member', 'key' => '12'],
        ];
        $graph['transitions'][] = [
            'key' => 'withdraw', 'from' => 'review', 'to' => 'done', 'operation' => 'withdraw',
            'action_kind' => 'withdraw', 'permission_keys' => ['subject.item.withdraw'],
            'human_required' => true, 'return_edge' => false, 'max_traversals' => null,
            'notification_intent' => null, 'task_intent' => null,
        ];
        $runtime->saveDraft(
            $this->definitionContext($firstContext, 'write'),
            'module.sample',
            'all-review',
            $graph,
            null,
            'workflow-draft-all-review',
        );
        $runtime->publishDefinition(
            $this->definitionContext($firstContext, 'publish'),
            'module.sample',
            'all-review',
            1,
            'workflow-publish-all-review',
        );
        $started = $runtime->startInstance(
            $this->definitionContext($firstContext, 'read'),
            'module.sample',
            'all-review',
            'subject.item',
            'subject-all',
            'revision-1',
            [],
            'workflow-start-all-review',
        );
        $partial = $runtime->applyTransition(
            $this->definitionContext($firstContext, 'read'),
            (string) $started->instanceKey,
            'approve',
            1,
            'revision-1',
            null,
            [],
            'workflow-first-all-review',
        );
        self::assertSame(1, $partial->instanceRevision);
        self::assertSame('review', $partial->currentNodeKey);

        $advanced = $runtime->applyTransition(
            $this->definitionContext($secondContext, 'read'),
            (string) $started->instanceKey,
            'approve',
            1,
            'revision-1',
            null,
            [],
            'workflow-second-all-review',
        );
        self::assertSame(2, $advanced->instanceRevision);
        self::assertSame('action', $advanced->currentNodeKey);

        $withdrawn = $runtime->startInstance(
            $this->definitionContext($firstContext, 'read'),
            'module.sample',
            'all-review',
            'subject.item',
            'subject-withdraw',
            'revision-1',
            [],
            'workflow-start-withdraw',
        );
        $withdrawPartial = $runtime->applyTransition(
            $this->definitionContext($firstContext, 'read'),
            (string) $withdrawn->instanceKey,
            'withdraw',
            1,
            'revision-1',
            null,
            [],
            'workflow-apply-withdraw',
        );
        self::assertSame('active', $withdrawPartial->instanceStatus);
        $cancelled = $runtime->applyTransition(
            $this->definitionContext($secondContext, 'read'),
            (string) $withdrawn->instanceKey,
            'withdraw',
            1,
            'revision-1',
            null,
            [],
            'workflow-apply-withdraw-second',
        );
        self::assertSame('cancelled', $cancelled->instanceStatus);
    }

    public function testDeclaredReturnTraversalStopsAtItsPersistedBound(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through P1-WORKFLOW-RUNTIME-001 MySQL qualification.');
        }
        [, $context] = $this->seedTenantContext('req_workflow_return');
        $runtime = new WorkflowRuntime(
            $this->pdo,
            new FixedWorkflowAssignments($this->pdo, 11),
            new AllowingWorkflowAuthorization($this->pdo),
            new FixedWorkflowSubject($this->pdo),
            new EmptyWorkflowAttachments($this->pdo),
            new RecordingWorkflowPublisher($this->pdo),
        );
        $graph = WorkflowGraphTest::validGraph();
        $graph['transitions'][1]['notification_intent'] = null;
        $graph['transitions'][1]['task_intent'] = null;
        $graph['transitions'][] = [
            'key' => 'return-to-review',
            'from' => 'action',
            'to' => 'review',
            'operation' => 'return',
            'action_kind' => 'return',
            'permission_keys' => ['subject.item.return'],
            'human_required' => false,
            'return_edge' => true,
            'max_traversals' => 1,
            'notification_intent' => null,
            'task_intent' => null,
        ];
        $runtime->saveDraft(
            $this->definitionContext($context, 'write'),
            'module.sample',
            'bounded-return',
            $graph,
            null,
            'workflow-return-draft',
        );
        $runtime->publishDefinition(
            $this->definitionContext($context, 'publish'),
            'module.sample',
            'bounded-return',
            1,
            'workflow-return-publish',
        );
        $started = $runtime->startInstance(
            $this->definitionContext($context, 'read'),
            'module.sample',
            'bounded-return',
            'subject.item',
            'subject-return',
            'revision-1',
            [],
            'workflow-return-start',
        );
        $advanced = $runtime->applyTransition(
            $this->definitionContext($context, 'read'),
            (string) $started->instanceKey,
            'approve',
            1,
            'revision-1',
            null,
            [],
            'workflow-return-approve-one',
        );
        $returned = $runtime->applyTransition(
            $this->definitionContext($context, 'read'),
            (string) $started->instanceKey,
            'return-to-review',
            (int) $advanced->instanceRevision,
            'revision-1',
            null,
            [],
            'workflow-return-once',
        );
        self::assertSame('review', $returned->currentNodeKey);
        $advancedAgain = $runtime->applyTransition(
            $this->definitionContext($context, 'read'),
            (string) $started->instanceKey,
            'approve',
            (int) $returned->instanceRevision,
            'revision-1',
            null,
            [],
            'workflow-return-approve-two',
        );

        try {
            $runtime->applyTransition(
                $this->definitionContext($context, 'read'),
                (string) $started->instanceKey,
                'return-to-review',
                (int) $advancedAgain->instanceRevision,
                'revision-1',
                null,
                [],
                'workflow-return-twice',
            );
            self::fail('The persisted return traversal bound must make the edge unavailable.');
        } catch (WorkflowException $exception) {
            self::assertSame('WORKFLOW_TRANSITION_UNAVAILABLE', $exception->errorCode);
        }
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM pa_workflow_event WHERE transition_key = 'return-to-review'",
        )->fetchColumn());
    }

    public function testStaleRevisionCrossTenantAndChangedReplayFailClosed(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through P1-WORKFLOW-RUNTIME-001 MySQL qualification.');
        }
        [, $context] = $this->seedTenantContext('req_workflow_boundaries');
        $runtime = new WorkflowRuntime(
            $this->pdo,
            new FixedWorkflowAssignments($this->pdo, 11),
            new AllowingWorkflowAuthorization($this->pdo),
            new FixedWorkflowSubject($this->pdo),
            new EmptyWorkflowAttachments($this->pdo),
            new RecordingWorkflowPublisher($this->pdo),
        );
        $runtime->saveDraft(
            $this->definitionContext($context, 'write'),
            'module.sample',
            'boundaries',
            WorkflowGraphTest::validGraph(),
            null,
            'workflow-draft-boundaries',
        );
        $runtime->publishDefinition(
            $this->definitionContext($context, 'publish'),
            'module.sample',
            'boundaries',
            1,
            'workflow-publish-boundaries',
        );
        $started = $runtime->startInstance(
            $this->definitionContext($context, 'read'),
            'module.sample',
            'boundaries',
            'subject.item',
            'subject-boundary',
            'revision-1',
            [],
            'workflow-start-boundaries',
        );

        try {
            $runtime->startInstance(
                $this->definitionContext($context, 'read'),
                'module.sample',
                'boundaries',
                'subject.item',
                'changed-subject',
                'revision-1',
                [],
                'workflow-start-boundaries',
            );
            self::fail('A changed request cannot reuse the same key.');
        } catch (ApiException $exception) {
            self::assertSame('IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
        }

        try {
            $runtime->applyTransition(
                $this->definitionContext($context, 'read'),
                (string) $started->instanceKey,
                'approve',
                2,
                'revision-1',
                null,
                [],
                'workflow-stale-instance',
            );
            self::fail('A stale instance revision must fail.');
        } catch (WorkflowException $exception) {
            self::assertSame('WORKFLOW_INSTANCE_CONFLICT', $exception->errorCode);
        }

        try {
            $runtime->applyAutomation(
                $this->definitionContext($context, 'read'),
                (string) $started->instanceKey,
                'approve',
                1,
                'revision-1',
                'job_' . str_repeat('e', 32),
            );
            self::fail('A background actor cannot satisfy a human review item.');
        } catch (WorkflowException $exception) {
            self::assertSame('WORKFLOW_TRANSITION_UNAVAILABLE', $exception->errorCode);
        }

        try {
            $runtime->applyTransition(
                $this->definitionContext($context, 'read'),
                (string) $started->instanceKey,
                'approve',
                1,
                'revision-stale',
                null,
                [],
                'workflow-stale-subject',
            );
            self::fail('A stale subject revision must fail.');
        } catch (WorkflowException $exception) {
            self::assertSame('WORKFLOW_SUBJECT_REVISION_CONFLICT', $exception->errorCode);
        }

        [$otherTenantId, $otherContext] = $this->seedTenantContext('req_workflow_cross_tenant', 21, 201);
        self::assertGreaterThan(1, $otherTenantId);
        try {
            $runtime->applyTransition(
                $this->definitionContext($otherContext, 'read'),
                (string) $started->instanceKey,
                'approve',
                1,
                'revision-1',
                null,
                [],
                'workflow-cross-tenant',
            );
            self::fail('A cross-Tenant instance must remain non-enumerating.');
        } catch (WorkflowException $exception) {
            self::assertSame('WORKFLOW_SUBJECT_NOT_FOUND', $exception->errorCode);
        }
    }

    public function testConcurrentHumanTransitionHasExactlyOneWinner(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through P1-WORKFLOW-RUNTIME-001 MySQL qualification.');
        }
        [, $context] = $this->seedTenantContext('req_workflow_concurrent_seed');
        $runtime = new WorkflowRuntime(
            $this->pdo,
            new FixedWorkflowAssignments($this->pdo, 11),
            new AllowingWorkflowAuthorization($this->pdo),
            new FixedWorkflowSubject($this->pdo),
            new EmptyWorkflowAttachments($this->pdo),
            new RecordingWorkflowPublisher($this->pdo),
        );
        $runtime->saveDraft(
            $this->definitionContext($context, 'write'),
            'module.sample',
            'concurrent',
            WorkflowGraphTest::validGraph(),
            null,
            'workflow-draft-concurrent',
        );
        $runtime->publishDefinition(
            $this->definitionContext($context, 'publish'),
            'module.sample',
            'concurrent',
            1,
            'workflow-publish-concurrent',
        );
        $started = $runtime->startInstance(
            $this->definitionContext($context, 'read'),
            'module.sample',
            'concurrent',
            'subject.item',
            'subject-concurrent',
            'revision-1',
            [],
            'workflow-start-concurrent',
        );

        $paths = [tempnam(sys_get_temp_dir(), 'peanut-wf01-a-'), tempnam(sys_get_temp_dir(), 'peanut-wf01-b-')];
        self::assertIsString($paths[0]);
        self::assertIsString($paths[1]);
        $processes = [];
        $parentSockets = [];
        foreach ($paths as $index => $path) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertIsArray($sockets);
            $processId = pcntl_fork();
            self::assertNotSame(-1, $processId);
            if ($processId === 0) {
                fclose($sockets[0]);
                fwrite($sockets[1], 'r');
                fread($sockets[1], 1);
                try {
                    $pdo = $this->connection();
                    $childRuntime = new WorkflowRuntime(
                        $pdo,
                        new FixedWorkflowAssignments($pdo, 11),
                        new AllowingWorkflowAuthorization($pdo),
                        new FixedWorkflowSubject($pdo),
                        new EmptyWorkflowAttachments($pdo),
                        new RecordingWorkflowPublisher($pdo),
                    );
                    $childContext = $this->tenantContext(
                        $context->tenantId,
                        11,
                        101,
                        'req_workflow_concurrent_' . $index,
                    );
                    $childRuntime->applyTransition(
                        $this->definitionContext($childContext, 'read'),
                        (string) $started->instanceKey,
                        'approve',
                        1,
                        'revision-1',
                        null,
                        [],
                        'workflow-concurrent-' . $index,
                    );
                    file_put_contents($path, 'success');
                    exit(0);
                } catch (WorkflowException $exception) {
                    file_put_contents($path, $exception->errorCode);
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($path, 'unexpected:' . $exception->getMessage());
                    exit(1);
                }
            }
            fclose($sockets[1]);
            $processes[] = $processId;
            $parentSockets[] = $sockets[0];
        }
        foreach ($parentSockets as $socket) {
            self::assertSame('r', fread($socket, 1));
        }
        foreach ($parentSockets as $socket) {
            fwrite($socket, 'g');
            fclose($socket);
        }
        foreach ($processes as $processId) {
            pcntl_waitpid($processId, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        $this->pdo = $this->connection();
        $this->admin = $this->connection(null);
        $outcomes = array_map(static function (string $path): string {
            $outcome = (string) file_get_contents($path);
            unlink($path);

            return $outcome;
        }, $paths);
        sort($outcomes, SORT_STRING);
        self::assertSame(['WORKFLOW_INSTANCE_CONFLICT', 'success'], $outcomes);
        self::assertSame(2, (int) $this->pdo->query('SELECT revision FROM pa_workflow_instance')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_workflow_event')->fetchColumn());
    }

    public function testRuntimeRollsBackAtEveryDatabaseWriteCheckpoint(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through P1-WORKFLOW-RUNTIME-001 MySQL qualification.');
        }
        [, $context] = $this->seedTenantContext('req_workflow_failure_matrix');
        $runtime = new WorkflowRuntime(
            $this->pdo,
            new FixedWorkflowAssignments($this->pdo, 11),
            new AllowingWorkflowAuthorization($this->pdo),
            new FixedWorkflowSubject($this->pdo),
            new EmptyWorkflowAttachments($this->pdo),
            new RecordingWorkflowPublisher($this->pdo),
        );

        foreach (['pa_workflow_definition', 'pa_tenant_audit_event'] as $index => $table) {
            $before = $this->runtimeState();
            $trigger = 'wf01_fail_draft_' . $index;
            $this->createInsertFailureTrigger($trigger, $table);
            try {
                $runtime->saveDraft(
                    $this->definitionContext($context, 'write'),
                    'module.sample',
                    'failure-draft-' . $index,
                    WorkflowGraphTest::validGraph(),
                    null,
                    'workflow-failure-draft-' . $index,
                );
                self::fail("Injected {$table} failure must abort saveDraft.");
            } catch (WorkflowException $exception) {
                self::assertSame('INTERNAL_ERROR', $exception->errorCode);
            } finally {
                $this->pdo->exec("DROP TRIGGER IF EXISTS `{$trigger}`");
            }
            self::assertSame($before, $this->runtimeState(), "State leaked after {$table} failure.");
        }

        $this->createIdempotencyCompletionFailureTrigger('wf01_fail_draft_completion');
        $before = $this->runtimeState();
        try {
            $runtime->saveDraft(
                $this->definitionContext($context, 'write'),
                'module.sample',
                'failure-draft-completion',
                WorkflowGraphTest::validGraph(),
                null,
                'workflow-failure-draft-completion',
            );
            self::fail('Injected idempotency completion failure must abort saveDraft.');
        } catch (WorkflowException $exception) {
            self::assertSame('INTERNAL_ERROR', $exception->errorCode);
        } finally {
            $this->pdo->exec('DROP TRIGGER IF EXISTS `wf01_fail_draft_completion`');
        }
        self::assertSame($before, $this->runtimeState());

        $runtime->saveDraft(
            $this->definitionContext($context, 'write'),
            'module.sample',
            'failure-start',
            WorkflowGraphTest::validGraph(),
            null,
            'workflow-failure-setup-draft',
        );
        $runtime->publishDefinition(
            $this->definitionContext($context, 'publish'),
            'module.sample',
            'failure-start',
            1,
            'workflow-failure-setup-publish',
        );

        foreach ([
            'pa_workflow_instance',
            'pa_workflow_work_item',
            'pa_workflow_event',
            'pa_tenant_audit_event',
        ] as $index => $table) {
            $before = $this->runtimeState();
            $trigger = 'wf01_fail_start_' . $index;
            $this->createInsertFailureTrigger($trigger, $table);
            try {
                $runtime->startInstance(
                    $this->definitionContext($context, 'read'),
                    'module.sample',
                    'failure-start',
                    'subject.item',
                    'subject-failure-' . $index,
                    'revision-1',
                    [],
                    'workflow-failure-start-' . $index,
                );
                self::fail("Injected {$table} failure must abort startInstance.");
            } catch (WorkflowException $exception) {
                self::assertSame('INTERNAL_ERROR', $exception->errorCode);
            } finally {
                $this->pdo->exec("DROP TRIGGER IF EXISTS `{$trigger}`");
            }
            self::assertSame($before, $this->runtimeState(), "State leaked after {$table} failure.");
        }

        $this->createIdempotencyCompletionFailureTrigger('wf01_fail_start_completion');
        $before = $this->runtimeState();
        try {
            $runtime->startInstance(
                $this->definitionContext($context, 'read'),
                'module.sample',
                'failure-start',
                'subject.item',
                'subject-failure-completion',
                'revision-1',
                [],
                'workflow-failure-start-completion',
            );
            self::fail('Injected idempotency completion failure must abort startInstance.');
        } catch (WorkflowException $exception) {
            self::assertSame('INTERNAL_ERROR', $exception->errorCode);
        } finally {
            $this->pdo->exec('DROP TRIGGER IF EXISTS `wf01_fail_start_completion`');
        }
        self::assertSame($before, $this->runtimeState());
    }

    public function testRemainingCommandsRollbackAtEveryWriteCheckpoint(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through P1-WORKFLOW-RUNTIME-001 MySQL qualification.');
        }
        [, $context] = $this->seedTenantContext('req_workflow_remaining_checkpoints');
        $runtime = new WorkflowRuntime(
            $this->pdo,
            new FixedWorkflowAssignments($this->pdo, 11),
            new AllowingWorkflowAuthorization($this->pdo),
            new FixedWorkflowSubject($this->pdo),
            new EmptyWorkflowAttachments($this->pdo),
            new RecordingWorkflowPublisher($this->pdo),
        );
        $graph = WorkflowGraphTest::validGraph();
        $graph['transitions'][1]['notification_intent'] = null;
        $graph['transitions'][1]['task_intent'] = null;
        $runtime->saveDraft(
            $this->definitionContext($context, 'write'),
            'module.sample',
            'remaining-checkpoints',
            $graph,
            null,
            'workflow-remaining-draft',
        );

        foreach ([
            ['insert', 'pa_workflow_definition_version'],
            ['update', 'pa_workflow_definition'],
            ['insert', 'pa_tenant_audit_event'],
        ] as $index => [$mutation, $table]) {
            $this->assertMutationRollback(
                'wf01_fail_publish_' . $index,
                $mutation,
                $table,
                fn(): WorkflowReceipt => $runtime->publishDefinition(
                    $this->definitionContext($context, 'publish'),
                    'module.sample',
                    'remaining-checkpoints',
                    1,
                    'workflow-fail-publish-' . $index,
                ),
            );
        }
        $this->assertIdempotencyCompletionRollback(
            'wf01_fail_publish_completion',
            fn(): WorkflowReceipt => $runtime->publishDefinition(
                $this->definitionContext($context, 'publish'),
                'module.sample',
                'remaining-checkpoints',
                1,
                'workflow-fail-publish-completion',
            ),
        );
        $runtime->publishDefinition(
            $this->definitionContext($context, 'publish'),
            'module.sample',
            'remaining-checkpoints',
            1,
            'workflow-remaining-publish',
        );

        foreach ([
            ['update', 'pa_workflow_definition'],
            ['insert', 'pa_tenant_audit_event'],
        ] as $index => [$mutation, $table]) {
            $this->assertMutationRollback(
                'wf01_fail_retire_' . $index,
                $mutation,
                $table,
                fn(): WorkflowReceipt => $runtime->retireDefinition(
                    $this->definitionContext($context, 'publish'),
                    'module.sample',
                    'remaining-checkpoints',
                    2,
                    'workflow-fail-retire-' . $index,
                ),
            );
        }
        $this->assertIdempotencyCompletionRollback(
            'wf01_fail_retire_completion',
            fn(): WorkflowReceipt => $runtime->retireDefinition(
                $this->definitionContext($context, 'publish'),
                'module.sample',
                'remaining-checkpoints',
                2,
                'workflow-fail-retire-completion',
            ),
        );

        $started = $runtime->startInstance(
            $this->definitionContext($context, 'read'),
            'module.sample',
            'remaining-checkpoints',
            'subject.item',
            'subject-checkpoints',
            'revision-1',
            [],
            'workflow-remaining-start',
        );
        foreach ([
            ['update', 'pa_workflow_work_item'],
            ['update', 'pa_workflow_instance'],
            ['insert', 'pa_workflow_event'],
            ['insert', 'pa_tenant_audit_event'],
        ] as $index => [$mutation, $table]) {
            $this->assertMutationRollback(
                'wf01_fail_transition_' . $index,
                $mutation,
                $table,
                fn(): WorkflowReceipt => $runtime->applyTransition(
                    $this->definitionContext($context, 'read'),
                    (string) $started->instanceKey,
                    'approve',
                    1,
                    'revision-1',
                    null,
                    [],
                    'workflow-fail-transition-' . $index,
                ),
            );
        }
        $this->assertIdempotencyCompletionRollback(
            'wf01_fail_transition_completion',
            fn(): WorkflowReceipt => $runtime->applyTransition(
                $this->definitionContext($context, 'read'),
                (string) $started->instanceKey,
                'approve',
                1,
                'revision-1',
                null,
                [],
                'workflow-fail-transition-completion',
            ),
        );
        $advanced = $runtime->applyTransition(
            $this->definitionContext($context, 'read'),
            (string) $started->instanceKey,
            'approve',
            1,
            'revision-1',
            null,
            [],
            'workflow-remaining-transition',
        );

        foreach ([
            ['update', 'pa_workflow_instance'],
            ['insert', 'pa_workflow_event'],
            ['insert', 'pa_tenant_audit_event'],
        ] as $index => [$mutation, $table]) {
            $this->assertMutationRollback(
                'wf01_fail_automation_' . $index,
                $mutation,
                $table,
                fn(): WorkflowReceipt => $runtime->applyAutomation(
                    $this->definitionContext($context, 'read'),
                    (string) $started->instanceKey,
                    'finish',
                    (int) $advanced->instanceRevision,
                    'revision-1',
                    'job_' . str_pad((string) ($index + 1), 32, (string) ($index + 1)),
                ),
            );
        }
        $this->assertIdempotencyCompletionRollback(
            'wf01_fail_automation_completion',
            fn(): WorkflowReceipt => $runtime->applyAutomation(
                $this->definitionContext($context, 'read'),
                (string) $started->instanceKey,
                'finish',
                (int) $advanced->instanceRevision,
                'revision-1',
                'job_' . str_repeat('f', 32),
            ),
        );
    }

    public function testQueriesAreTenantBoundPaginatedAndFieldMinimized(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through P1-WORKFLOW-RUNTIME-001 MySQL qualification.');
        }
        [, $context] = $this->seedTenantContext('req_workflow_queries');
        $runtime = new WorkflowRuntime(
            $this->pdo,
            new FixedWorkflowAssignments($this->pdo, 11),
            new AllowingWorkflowAuthorization($this->pdo),
            new FixedWorkflowSubject($this->pdo),
            new EmptyWorkflowAttachments($this->pdo),
            new RecordingWorkflowPublisher($this->pdo),
        );
        $draft = $runtime->saveDraft(
            $this->definitionContext($context, 'write'),
            'module.sample',
            'query-one',
            WorkflowGraphTest::validGraph(),
            null,
            'workflow-query-one-draft',
        );
        $runtime->publishDefinition(
            $this->definitionContext($context, 'publish'),
            'module.sample',
            'query-one',
            1,
            'workflow-query-one-publish',
        );
        $secondDraft = $runtime->saveDraft(
            $this->definitionContext($context, 'write'),
            'module.sample',
            'query-two',
            WorkflowGraphTest::validGraph(),
            null,
            'workflow-query-two-draft',
        );
        $started = $runtime->startInstance(
            $this->definitionContext($context, 'read'),
            'module.sample',
            'query-one',
            'subject.item',
            'subject-query',
            'revision-1',
            [],
            'workflow-query-start',
        );
        $query = new WorkflowQueryService($this->pdo, new AllowingWorkflowAuthorization($this->pdo));

        $definition = $query->definition(
            $this->definitionContext($context, 'read'),
            'module.sample',
            'query-one',
        );
        self::assertSame([
            'definition_id', 'module_key', 'workflow_key', 'status', 'draft_revision',
            'draft_graph_sha256', 'latest_version', 'versions',
        ], array_keys($definition));
        self::assertArrayNotHasKey('draft_graph', $definition);
        self::assertCount(1, $definition['versions']);

        $definitionDraft = $query->definitionDraft(
            $this->definitionContext($context, 'write'),
            'module.sample',
            'query-one',
        );
        self::assertSame([...array_keys($definition), 'draft_graph'], array_keys($definitionDraft));
        self::assertSame(
            WorkflowGraph::fromArray(WorkflowGraphTest::validGraph())->sha256,
            WorkflowGraph::fromArray($definitionDraft['draft_graph'])->sha256,
        );

        $firstPage = $query->definitions($this->definitionContext($context, 'read'), 'all', 1, 1);
        $secondPage = $query->definitions($this->definitionContext($context, 'read'), null, 2, 1);
        self::assertSame($secondDraft->definitionId, $firstPage[0]['definition_id']);
        self::assertSame($draft->definitionId, $secondPage[0]['definition_id']);
        self::assertSame([
            'definition_id', 'module_key', 'workflow_key', 'status', 'draft_revision',
            'draft_graph_sha256', 'latest_version',
        ], array_keys($firstPage[0]));

        $instance = $query->instance(
            $this->definitionContext($context, 'read'),
            (string) $started->instanceKey,
        );
        self::assertSame([
            'instance_key', 'definition_id', 'definition_version', 'subject_type', 'subject_key',
            'subject_revision_key', 'subject_revision_sha256', 'current_node_key', 'status', 'revision',
        ], array_keys($instance));
        self::assertArrayNotHasKey('initiated_by_member_id', $instance);
        self::assertArrayNotHasKey('last_actor_member_id', $instance);

        $workItems = $query->workItems(
            $this->definitionContext($context, 'read'),
            (string) $started->instanceKey,
            'pending',
            1,
            1,
        );
        self::assertCount(1, $workItems);
        self::assertArrayNotHasKey('id', $workItems[0]);
        self::assertArrayNotHasKey('tenant_id', $workItems[0]);
        self::assertArrayNotHasKey('instance_id', $workItems[0]);

        $events = $query->events(
            $this->definitionContext($context, 'read'),
            (string) $started->instanceKey,
            0,
            1,
        );
        self::assertCount(1, $events);
        self::assertSame(1, $events[0]['sequence_no']);
        self::assertArrayNotHasKey('id', $events[0]);
        self::assertArrayNotHasKey('tenant_id', $events[0]);
        self::assertArrayNotHasKey('instance_id', $events[0]);

        [, $otherContext] = $this->seedTenantContext('req_workflow_query_other', 21, 201);
        try {
            $query->instance($this->definitionContext($otherContext, 'read'), (string) $started->instanceKey);
            self::fail('A cross-Tenant query must be indistinguishable from an unknown instance.');
        } catch (WorkflowException $exception) {
            self::assertSame('WORKFLOW_SUBJECT_NOT_FOUND', $exception->errorCode);
            self::assertSame(404, $exception->httpStatus);
        }

        foreach ([[0, 1], [1, 0], [1, 101]] as [$page, $pageSize]) {
            try {
                $query->definitions($this->definitionContext($context, 'read'), null, $page, $pageSize);
                self::fail('Invalid query pagination must fail closed.');
            } catch (WorkflowException $exception) {
                self::assertSame('WORKFLOW_DEFINITION_INVALID', $exception->errorCode);
            }
        }
    }

    public function testPersistedGraphDigestAndInternalJsonCorruptionMapToInternalError(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through P1-WORKFLOW-RUNTIME-001 MySQL qualification.');
        }
        [, $context] = $this->seedTenantContext('req_workflow_corruption');
        $runtime = new WorkflowRuntime(
            $this->pdo,
            new FixedWorkflowAssignments($this->pdo, 11),
            new AllowingWorkflowAuthorization($this->pdo),
            new FixedWorkflowSubject($this->pdo),
            new EmptyWorkflowAttachments($this->pdo),
            new RecordingWorkflowPublisher($this->pdo),
        );
        $runtime->saveDraft(
            $this->definitionContext($context, 'write'),
            'module.sample',
            'corruption',
            WorkflowGraphTest::validGraph(),
            null,
            'workflow-corruption-draft',
        );
        $runtime->publishDefinition(
            $this->definitionContext($context, 'publish'),
            'module.sample',
            'corruption',
            1,
            'workflow-corruption-publish',
        );
        $started = $runtime->startInstance(
            $this->definitionContext($context, 'read'),
            'module.sample',
            'corruption',
            'subject.item',
            'subject-corruption',
            'revision-1',
            [],
            'workflow-corruption-start',
        );
        $query = new WorkflowQueryService($this->pdo, new AllowingWorkflowAuthorization($this->pdo));
        $graphDigest = WorkflowGraph::fromArray(WorkflowGraphTest::validGraph())->sha256;

        $this->pdo->exec("UPDATE pa_workflow_definition SET draft_graph_sha256 = '" . str_repeat('0', 64) . "'");
        $this->assertInternalQueryFailure(fn(): array => $query->definition(
            $this->definitionContext($context, 'read'),
            'module.sample',
            'corruption',
        ));
        try {
            $runtime->publishDefinition(
                $this->definitionContext($context, 'publish'),
                'module.sample',
                'corruption',
                2,
                'workflow-corruption-republish',
            );
            self::fail('A corrupted persisted graph digest must abort publication.');
        } catch (WorkflowException $exception) {
            self::assertSame('INTERNAL_ERROR', $exception->errorCode);
        }
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM pa_workflow_definition_version',
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT latest_version FROM pa_workflow_definition',
        )->fetchColumn());
        $this->pdo->exec("UPDATE pa_workflow_definition SET draft_graph_sha256 = '{$graphDigest}'");

        $this->pdo->exec("UPDATE pa_workflow_definition_version SET graph_sha256 = '" . str_repeat('0', 64) . "'");
        $this->assertInternalQueryFailure(fn(): array => $query->instance(
            $this->definitionContext($context, 'read'),
            (string) $started->instanceKey,
        ));
        $this->pdo->exec("UPDATE pa_workflow_definition_version SET graph_sha256 = '{$graphDigest}'");

        $this->pdo->exec("UPDATE pa_workflow_event SET metadata_json = JSON_QUOTE('internal-corruption')");
        $this->assertInternalQueryFailure(fn(): array => $query->events(
            $this->definitionContext($context, 'read'),
            (string) $started->instanceKey,
            0,
            10,
        ));
    }

    public function testInvisibleQueryAuthorizationIsTheSameNotFoundShape(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through P1-WORKFLOW-RUNTIME-001 MySQL qualification.');
        }
        [, $context] = $this->seedTenantContext('req_workflow_query_authz');
        $runtime = new WorkflowRuntime(
            $this->pdo,
            new FixedWorkflowAssignments($this->pdo, 11),
            new AllowingWorkflowAuthorization($this->pdo),
            new FixedWorkflowSubject($this->pdo),
            new EmptyWorkflowAttachments($this->pdo),
            new RecordingWorkflowPublisher($this->pdo),
        );
        $runtime->saveDraft(
            $this->definitionContext($context, 'write'),
            'module.sample',
            'query-authz',
            WorkflowGraphTest::validGraph(),
            null,
            'workflow-query-authz-draft',
        );
        $runtime->publishDefinition(
            $this->definitionContext($context, 'publish'),
            'module.sample',
            'query-authz',
            1,
            'workflow-query-authz-publish',
        );
        $started = $runtime->startInstance(
            $this->definitionContext($context, 'read'),
            'module.sample',
            'query-authz',
            'subject.item',
            'subject-query-authz',
            'revision-1',
            [],
            'workflow-query-authz-start',
        );
        $query = new WorkflowQueryService($this->pdo, new DenyingWorkflowAuthorization($this->pdo));

        try {
            $query->instance($this->definitionContext($context, 'read'), (string) $started->instanceKey);
            self::fail('An invisible instance must use the non-enumerating not-found shape.');
        } catch (WorkflowException $exception) {
            self::assertSame('WORKFLOW_SUBJECT_NOT_FOUND', $exception->errorCode);
            self::assertSame(404, $exception->httpStatus);
        }
    }

    public function testArchivedAndCrossTenantAttachmentsFailBeforeWorkflowWrites(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through P1-WORKFLOW-RUNTIME-001 MySQL qualification.');
        }
        [, $context] = $this->seedTenantContext('req_workflow_attachment');
        $setupRuntime = new WorkflowRuntime(
            $this->pdo,
            new FixedWorkflowAssignments($this->pdo, 11),
            new AllowingWorkflowAuthorization($this->pdo),
            new FixedWorkflowSubject($this->pdo),
            new EmptyWorkflowAttachments($this->pdo),
            new RecordingWorkflowPublisher($this->pdo),
        );
        $setupRuntime->saveDraft(
            $this->definitionContext($context, 'write'),
            'module.sample',
            'attachment-boundary',
            WorkflowGraphTest::validGraph(),
            null,
            'workflow-attachment-draft',
        );
        $setupRuntime->publishDefinition(
            $this->definitionContext($context, 'publish'),
            'module.sample',
            'attachment-boundary',
            1,
            'workflow-attachment-publish',
        );

        foreach (['archived', 'cross-tenant'] as $index => $reason) {
            $runtime = new WorkflowRuntime(
                $this->pdo,
                new FixedWorkflowAssignments($this->pdo, 11),
                new AllowingWorkflowAuthorization($this->pdo),
                new FixedWorkflowSubject($this->pdo),
                new RejectingWorkflowAttachments($this->pdo),
                new RecordingWorkflowPublisher($this->pdo),
            );
            $before = $this->runtimeState();
            try {
                $runtime->startInstance(
                    $this->definitionContext($context, 'read'),
                    'module.sample',
                    'attachment-boundary',
                    'subject.item',
                    'subject-attachment-' . $index,
                    'revision-1',
                    ['file_' . str_repeat((string) ($index + 1), 32)],
                    'workflow-attachment-' . $reason,
                );
                self::fail("A {$reason} attachment must fail closed.");
            } catch (WorkflowException $exception) {
                self::assertSame('WORKFLOW_ATTACHMENT_UNAVAILABLE', $exception->errorCode);
            }
            self::assertSame($before, $this->runtimeState());
        }
    }

    private function definitionContext(TenantContext $context, string $operation): AuthorizedOperationContext
    {
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $context,
            Package::DEFINITION_RESOURCE_KEY,
            $operation,
            [],
            hash('sha256', "definition:{$operation}"),
        ));
    }

    /** @param callable(): array<mixed> $query */
    private function assertInternalQueryFailure(callable $query): void
    {
        try {
            $query();
            self::fail('Persisted workflow corruption must fail closed.');
        } catch (WorkflowException $exception) {
            self::assertSame('INTERNAL_ERROR', $exception->errorCode);
            self::assertSame(500, $exception->httpStatus);
        }
    }

    private function tenantContext(int $tenantId, int $memberId, int $accountId, string $requestId): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $accountId,
            '01J00000000000000000000000',
            $tenantId,
            $accountId,
            $memberId,
            'admin-web',
            new DateTimeImmutable('2030-01-01T00:00:00.000Z'),
            1,
        ), $requestId);
    }

    /** @return array{int, TenantContext} */
    private function seedTenantContext(string $requestId, int $memberId = 11, int $accountId = 101): array
    {
        $this->pdo->exec('INSERT INTO pa_tenant VALUES ()');
        $tenantId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO pa_tenant_member (id, tenant_id, account_id, status) VALUES (?, ?, ?, 'active')")
            ->execute([$memberId, $tenantId, $accountId]);

        return [$tenantId, $this->tenantContext($tenantId, $memberId, $accountId, $requestId)];
    }

    private function createKernelFixtures(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (id)
) ENGINE=InnoDB
SQL);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant_member (
  id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_workflow_test_member (tenant_id, id)
) ENGINE=InnoDB
SQL);
        $this->pdo->exec(IdempotencySchema::tenant());
        $this->pdo->exec(KernelSchema::createSql('pa_tenant_audit_event'));
    }

    private function connection(?string $database = self::DATABASE): PDO
    {
        $port = (int) getenv('DB_PORT');
        $password = getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev';
        $databasePart = $database === null ? '' : ';dbname=' . $database;

        return new PDO(
            "mysql:host=127.0.0.1;port={$port}{$databasePart};charset=utf8mb4",
            'root',
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }

    private function createInsertFailureTrigger(string $trigger, string $table): void
    {
        $allowed = [
            'pa_workflow_definition',
            'pa_workflow_instance',
            'pa_workflow_work_item',
            'pa_workflow_event',
            'pa_tenant_audit_event',
        ];
        if (!in_array($table, $allowed, true)) {
            throw new RuntimeException('The failure trigger table is outside the WF01 contract.');
        }
        $this->pdo->exec(<<<SQL
CREATE TRIGGER `{$trigger}` BEFORE INSERT ON `{$table}`
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wf01 injected failure'
SQL);
    }

    /** @param callable(): WorkflowReceipt $command */
    private function assertMutationRollback(
        string $trigger,
        string $mutation,
        string $table,
        callable $command,
    ): void {
        $allowedTables = [
            'pa_workflow_definition',
            'pa_workflow_definition_version',
            'pa_workflow_instance',
            'pa_workflow_work_item',
            'pa_workflow_event',
            'pa_tenant_audit_event',
        ];
        if (!in_array($mutation, ['insert', 'update'], true) || !in_array($table, $allowedTables, true)) {
            throw new RuntimeException('The failure checkpoint is outside the WF01 contract.');
        }
        $sqlMutation = strtoupper($mutation);
        $before = $this->runtimeState();
        $this->pdo->exec(<<<SQL
CREATE TRIGGER `{$trigger}` BEFORE {$sqlMutation} ON `{$table}`
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wf01 injected mutation failure'
SQL);
        try {
            $command();
            self::fail("Injected {$mutation} failure on {$table} must abort the command.");
        } catch (WorkflowException $exception) {
            self::assertSame('INTERNAL_ERROR', $exception->errorCode);
        } finally {
            $this->pdo->exec("DROP TRIGGER IF EXISTS `{$trigger}`");
        }
        self::assertSame($before, $this->runtimeState(), "State leaked after {$mutation} failure on {$table}.");
    }

    /** @param callable(): WorkflowReceipt $command */
    private function assertIdempotencyCompletionRollback(string $trigger, callable $command): void
    {
        $before = $this->runtimeState();
        $this->createIdempotencyCompletionFailureTrigger($trigger);
        try {
            $command();
            self::fail('Injected idempotency completion failure must abort the command.');
        } catch (WorkflowException $exception) {
            self::assertSame('INTERNAL_ERROR', $exception->errorCode);
        } finally {
            $this->pdo->exec("DROP TRIGGER IF EXISTS `{$trigger}`");
        }
        self::assertSame($before, $this->runtimeState(), 'State leaked after idempotency completion failure.');
    }

    private function createIdempotencyCompletionFailureTrigger(string $trigger): void
    {
        $this->pdo->exec(<<<SQL
CREATE TRIGGER `{$trigger}` BEFORE UPDATE ON `pa_tenant_idempotency_record`
FOR EACH ROW
BEGIN
  IF NEW.status = 'completed' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wf01 injected completion failure';
  END IF;
END
SQL);
    }

    /** @return array<string, int> */
    private function runtimeState(): array
    {
        $state = [];
        foreach ([
            'pa_workflow_definition',
            'pa_workflow_definition_version',
            'pa_workflow_instance',
            'pa_workflow_work_item',
            'pa_workflow_event',
            'pa_tenant_audit_event',
            'pa_tenant_idempotency_record',
        ] as $table) {
            $state[$table] = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        }
        $state['definition_revision'] = (int) $this->pdo->query(
            'SELECT COALESCE(SUM(revision), 0) FROM pa_workflow_definition',
        )->fetchColumn();
        $state['definition_latest_version'] = (int) $this->pdo->query(
            'SELECT COALESCE(SUM(latest_version), 0) FROM pa_workflow_definition',
        )->fetchColumn();
        foreach (['draft', 'active', 'retired'] as $status) {
            $state['definition_status_' . $status] = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM pa_workflow_definition WHERE status = '{$status}'",
            )->fetchColumn();
        }
        $state['instance_revision'] = (int) $this->pdo->query(
            'SELECT COALESCE(SUM(revision), 0) FROM pa_workflow_instance',
        )->fetchColumn();
        foreach (['active', 'completed', 'cancelled'] as $status) {
            $state['instance_status_' . $status] = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM pa_workflow_instance WHERE status = '{$status}'",
            )->fetchColumn();
        }
        foreach (['pending', 'completed', 'cancelled'] as $status) {
            $state['work_item_status_' . $status] = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM pa_workflow_work_item WHERE status = '{$status}'",
            )->fetchColumn();
        }

        return $state;
    }
}

final readonly class FixedWorkflowAssignments implements WorkflowAssignmentResolver
{
    public function __construct(private PDO $pdo, private int $memberId) {}

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function resolve(
        AuthorizedOperationContext $context,
        array $rules,
        int $initiatorMemberId,
        ?int $previousActorMemberId,
    ): array {
        return [['source_kind' => 'role', 'source_key' => 'reviewer', 'member_id' => $this->memberId]];
    }
}

final readonly class DeclaredWorkflowAssignments implements WorkflowAssignmentResolver
{
    public function __construct(private PDO $pdo) {}

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function resolve(
        AuthorizedOperationContext $context,
        array $rules,
        int $initiatorMemberId,
        ?int $previousActorMemberId,
    ): array {
        $resolved = [];
        foreach ($rules as $rule) {
            if ($rule['kind'] !== 'member' || !is_string($rule['key'])) {
                throw new RuntimeException('This fixture accepts declared member rules only.');
            }
            $resolved[] = [
                'source_kind' => 'member',
                'source_key' => $rule['key'],
                'member_id' => (int) $rule['key'],
            ];
        }

        return $resolved;
    }
}

final readonly class AllowingWorkflowAuthorization implements WorkflowAuthorizationResolver
{
    public function __construct(private PDO $pdo) {}

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function authorize(
        AuthorizedOperationContext $trustedBasis,
        string $resourceKey,
        string $operation,
        array $permissionKeys,
        string $subjectKey,
    ): AuthorizedOperationContext {
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $trustedBasis->tenantContext,
            $resourceKey,
            $operation,
            [new RequestedTargetSet($resourceKey, [$subjectKey])],
            hash('sha256', implode('|', $permissionKeys)),
        ));
    }
}

final readonly class DenyingWorkflowAuthorization implements WorkflowAuthorizationResolver
{
    public function __construct(private PDO $pdo) {}

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function authorize(
        AuthorizedOperationContext $trustedBasis,
        string $resourceKey,
        string $operation,
        array $permissionKeys,
        string $subjectKey,
    ): AuthorizedOperationContext {
        throw new ApiException('AUTHZ_PERMISSION_DENIED', 403, 'The operation is not permitted.');
    }
}

final readonly class FixedWorkflowSubject implements WorkflowSubjectRevisionResolver
{
    public function __construct(private PDO $pdo) {}

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function resolve(
        AuthorizedOperationContext $context,
        string $subjectType,
        string $subjectKey,
        string $expectedRevisionKey,
    ): array {
        return ['revision_key' => $expectedRevisionKey, 'sha256' => hash('sha256', $expectedRevisionKey)];
    }
}

final readonly class EmptyWorkflowAttachments implements WorkflowAttachmentResolver
{
    public function __construct(private PDO $pdo) {}

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function snapshot(AuthorizedOperationContext $context, string $fileKey): WorkflowAttachment
    {
        throw new RuntimeException('The runtime test declares no attachment keys.');
    }
}

final readonly class RejectingWorkflowAttachments implements WorkflowAttachmentResolver
{
    public function __construct(private PDO $pdo) {}

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function snapshot(AuthorizedOperationContext $context, string $fileKey): WorkflowAttachment
    {
        throw WorkflowException::attachmentUnavailable();
    }
}

final class RecordingWorkflowPublisher implements WorkflowSideEffectPublisher
{
    public int $publishCount = 0;

    public function __construct(private readonly PDO $pdo) {}

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function publish(
        PDO $pdo,
        AuthorizedOperationContext $context,
        WorkflowTransitionEffects $effects,
        string $parentIdempotencyKey,
    ): void {
        if ($pdo !== $this->pdo) {
            throw new RuntimeException('Workflow side effects must use the command PDO.');
        }
        ++$this->publishCount;
    }
}

final readonly class FailingWorkflowPublisher implements WorkflowSideEffectPublisher
{
    public function __construct(private PDO $pdo) {}

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function publish(
        PDO $pdo,
        AuthorizedOperationContext $context,
        WorkflowTransitionEffects $effects,
        string $parentIdempotencyKey,
    ): void {
        throw new RuntimeException('provider-secret database detail');
    }
}
