<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Tests\Integration\Application;

use DateTimeImmutable;
use LogicException;
use PDO;
use PeanutAdmin\DataPermission\Catalog\ThinkPhpResourceOperationCatalog;
use PeanutAdmin\DataPermission\Engine\DataPermissionEngine;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\DataPermission\Policy\ThinkPhpPolicyRepository;
use PeanutAdmin\DataPermission\Policy\PolicyCache;
use PeanutAdmin\DataPermission\Provider\ResourceProviderRegistry;
use PeanutAdmin\DataPermission\Provider\SharedMasterScopeProviderRegistry;
use PeanutAdmin\DataPermission\Target\ResolvedResourceTargets;
use PeanutAdmin\DataPermission\Target\ResourceTargetResolver;
use PeanutAdmin\DataPermission\Target\TargetCatalogProviderRegistry;
use PeanutAdmin\DataPermission\Target\TargetResolverRegistry;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PeanutAdmin\DataPermission\Tests\Integration\Schema\DataPermissionMigrationRunner;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Async\TrustedEnvelopeCodec;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\AuthorizationException;
use PeanutAdmin\Kernel\Authorization\ThinkPhpTenantAuthorizationRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\ThinkPhpAuthorizationRevisionRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;
use PeanutAdmin\Kernel\Authorization\Persistence\ProtectedResourceDefinition;
use PeanutAdmin\Kernel\Authorization\Persistence\ResourceOperationDefinition;
use PeanutAdmin\Kernel\Authorization\Persistence\TargetTypeDefinition;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Kernel\Idempotency\IdempotencyService;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Persistence\Model\TenantMember;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;
use PeanutAdmin\NotificationSms\Application\AttachmentReference;
use PeanutAdmin\NotificationSms\Application\AttachmentResolver as NotificationAttachmentResolver;
use PeanutAdmin\NotificationSms\Application\NotificationException;
use PeanutAdmin\NotificationSms\Application\NotificationService;
use PeanutAdmin\NotificationSms\Application\RecipientResolver;
use PeanutAdmin\NotificationSms\Application\RecipientSnapshot;
use PeanutAdmin\NotificationSms\Application\TemplateRenderer;
use PeanutAdmin\NotificationSms\Database\Schema as NotificationSchema;
use PeanutAdmin\NotificationSms\Package as NotificationPackage;
use PeanutAdmin\NotificationSms\Persistence\NotificationStore;
use PeanutAdmin\TaskJob\Database\Schema as TaskJobSchema;
use PeanutAdmin\TaskJob\Persistence\TaskJobStore;
use PeanutAdmin\TaskJob\Submission\TaskSubmission;
use PeanutAdmin\TaskJob\Submission\TaskSubmissionProvider;
use PeanutAdmin\TaskJob\Submission\TaskSubmissionRegistry;
use PeanutAdmin\TaskJob\Submission\TrustedJobPublisher;
use PeanutAdmin\Testing\Workflow\WorkflowAtomicityContractHarness;
use PeanutAdmin\Workflow\Adapter\WorkflowAssignmentResolver;
use PeanutAdmin\Workflow\Adapter\WorkflowAttachment;
use PeanutAdmin\Workflow\Adapter\WorkflowAttachmentResolver;
use PeanutAdmin\Workflow\Adapter\WorkflowAuthorizationResolver;
use PeanutAdmin\Workflow\Adapter\WorkflowNotificationIntent;
use PeanutAdmin\Workflow\Adapter\WorkflowSideEffectPublisher;
use PeanutAdmin\Workflow\Adapter\WorkflowSubjectRevisionResolver;
use PeanutAdmin\Workflow\Adapter\WorkflowTaskIntent;
use PeanutAdmin\Workflow\Adapter\WorkflowTransitionEffects;
use PeanutAdmin\Workflow\Application\WorkflowException;
use PeanutAdmin\Workflow\Application\WorkflowRuntime;
use PeanutAdmin\Workflow\Database\Schema as WorkflowSchema;
use PeanutAdmin\Workflow\Package as WorkflowPackage;
use PeanutAdmin\Workflow\Persistence\ThinkPhpWorkflowRepository;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use think\App;
use think\db\exception\PDOException as ThinkPhpPDOException;
use think\facade\Db;
use Throwable;

require_once dirname(__DIR__, 4) . '/kernel/tests/Integration/Schema/DatabaseTestCase.php';
require_once dirname(__DIR__, 4) . '/data-permission/tests/Integration/Schema/DataPermissionMigrationRunner.php';

#[Group('integration')]
final class WorkflowCapabilityCompositionTest extends DatabaseTestCase
{
    private const NOW = '2026-08-11 08:00:00.000';
    private const HOST_MODULE = 'host.workflow';
    public const SUBJECT_RESOURCE = 'host.workflow.subject';
    private const SUBJECT_KEY = 'subject-001';
    private const SUBJECT_REVISION = 'revision-001';
    private const START_PERMISSION = 'host.workflow.subject.start';
    private const APPROVE_PERMISSION = 'host.workflow.subject.approve';
    public const TASK_TYPE = 'workflow.finalize';
    private const TEMPLATE_KEY = 'workflow.approved';
    private const ENVELOPE_KEY = 'workflow-capability-composition-key';

    private int $tenantId;
    private int $accountId;
    private int $memberId;
    private int $roleId;
    private App $app;
    private bool $appInitialized = false;
    private TenantContext $tenantContext;
    private TenantAuthorizationEvaluator $functionalAuthorization;
    private DataPermissionEngine $dataAuthorization;
    private ThinkPhpAuthorizationCatalogRepository $catalog;
    private CapabilityWorkflowAuthorization $workflowAuthorization;
    private NotificationService $notifications;
    private TrustedJobPublisher $tasks;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
        }
        putenv('DB_HOST=127.0.0.1');
        putenv('DB_PORT=' . (getenv('MYSQL_PORT') ?: '3306'));
        putenv('DB_DATABASE=' . self::DATABASE);
        putenv('DB_USERNAME=root');
        putenv('DB_PASSWORD=' . (getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev'));
        $this->app = new App(dirname(__DIR__, 6) . '/backend');
        $this->app->initialize();
        $this->appInitialized = true;

        $this->runner->migrate();
        (new DataPermissionMigrationRunner(
            self::DATABASE,
            '127.0.0.1',
            (int) (getenv('MYSQL_PORT') ?: 3306),
            'root',
            getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev',
        ))->migrate();
        foreach (TaskJobSchema::tableNames() as $table) {
            $this->database->exec(TaskJobSchema::createSql($table));
        }
        foreach (NotificationSchema::tableNames() as $table) {
            $this->database->exec(NotificationSchema::createSql($table));
        }
        foreach (WorkflowSchema::createSql() as $statement) {
            $this->database->exec($statement);
        }
        $this->createHostFixtureTables();
        $this->seedAuthorities();
        $this->notifications = new NotificationService(
            new NotificationStore(),
            new CapabilityRecipientResolver(),
            new CapabilityNotificationAttachments(),
            new TemplateRenderer(),
        );
        $this->tasks = new TrustedJobPublisher(
            new TaskJobStore(),
            new TaskSubmissionRegistry([new CapabilityTaskSubmissionProvider()]),
            new TrustedEnvelopeCodec(self::ENVELOPE_KEY),
        );
        $this->notifications->putTemplate(
            $this->notificationContext(),
            self::TEMPLATE_KEY,
            'Workflow approved',
            'Workflow approved',
            'The workflow transition was approved.',
            ['inbox'],
            [],
            null,
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }
        if ($this->appInitialized) {
            restore_error_handler();
            restore_exception_handler();
        }
        parent::tearDown();
    }

    public function testHarnessExecutesRealWritesInsideTheThinkPhpTransaction(): void
    {
        $checkpoints = [
            'instance_written',
            'work_item_written',
            'event_written',
            'audit_written',
            'notification_written',
            'task_written',
            'idempotency_completed',
        ];
        $operation = static function (callable $checkpoint) use ($checkpoints): void {
            Db::transaction(function () use ($checkpoints, $checkpoint): void {
                foreach ($checkpoints as $name) {
                    Db::table('test_workflow_atomicity')->insert(['checkpoint_name' => $name]);
                    $checkpoint($name);
                }
            });
        };

        (new WorkflowAtomicityContractHarness())->assertAtomic(
            $operation,
            [
                'workflow' => fn(): int => $this->checkpointCount(['instance_written', 'work_item_written', 'event_written']),
                'audit' => fn(): int => $this->checkpointCount(['audit_written']),
                'notification' => fn(): int => $this->checkpointCount(['notification_written']),
                'task' => fn(): int => $this->checkpointCount(['task_written']),
                'idempotency' => fn(): int => $this->checkpointCount(['idempotency_completed']),
            ],
            ['workflow' => 3, 'audit' => 1, 'notification' => 1, 'task' => 1, 'idempotency' => 1],
            $checkpoints,
        );
        self::assertSame(count($checkpoints), $this->checkpointCount($checkpoints));
        self::assertFalse($this->database->inTransaction());
    }

    public function testComposesRealAuthorizationNotificationAndTaskContractsWithoutReplayDuplicates(): void
    {
        $publisher = $this->publisher($this->notificationContext());
        $runtime = $this->runtime($publisher);
        $definitionRead = $this->definitionContext('read');
        $runtime->saveDraft(
            $this->definitionContext('write'),
            self::HOST_MODULE,
            'approval',
            $this->graph(),
            null,
            'workflow-composition-draft',
        );
        $runtime->publishDefinition(
            $this->definitionContext('publish'),
            self::HOST_MODULE,
            'approval',
            1,
            'workflow-composition-publish',
        );
        $started = $runtime->startInstance(
            $definitionRead,
            self::HOST_MODULE,
            'approval',
            self::SUBJECT_RESOURCE,
            self::SUBJECT_KEY,
            self::SUBJECT_REVISION,
            [],
            'workflow-composition-start',
        );
        self::assertSame('review', $started->currentNodeKey);
        self::assertGreaterThan(0, $this->workflowAuthorization->decideTargetCalls);

        $missingProvider = $this->publisher(null);
        $this->expectTransitionRollback($missingProvider, (string) $started->instanceKey, 'WORKFLOW_PROVIDER_UNAVAILABLE');

        $collisionEffects = new WorkflowTransitionEffects(
            (string) $started->instanceKey,
            2,
            'approve',
            self::SUBJECT_REVISION,
            [],
            [new WorkflowTaskIntent(self::TASK_TYPE)],
        );
        $taskChildKey = $collisionEffects->childKey('task', 0);
        $taskRequestHash = $collisionEffects->requestHash($collisionEffects->taskIntents[0]);
        $collidingRequestHash = ($taskRequestHash[0] === '0' ? '1' : '0') . substr($taskRequestHash, 1);
        $collision = $this->database->prepare(<<<'SQL'
INSERT INTO test_workflow_effect_child (child_key, effect_kind, request_hash)
VALUES (:child_key, 'task', :request_hash)
SQL);
        $collision->execute(['child_key' => $taskChildKey, 'request_hash' => $collidingRequestHash]);
        $this->expectTransitionRollback($publisher, (string) $started->instanceKey, 'WORKFLOW_PROVIDER_UNAVAILABLE');
        $deleteCollision = $this->database->prepare('DELETE FROM test_workflow_effect_child WHERE child_key = :child_key');
        $deleteCollision->execute(['child_key' => $taskChildKey]);

        $wrongNotificationContext = $this->publisher($definitionRead);
        $this->expectTransitionRollback(
            $wrongNotificationContext,
            (string) $started->instanceKey,
            'WORKFLOW_PROVIDER_UNAVAILABLE',
        );

        $wrongTaskContext = new TrustedJobPublisher(
            new TaskJobStore(),
            new TaskSubmissionRegistry([new MismatchedCapabilityTaskSubmissionProvider()]),
            new TrustedEnvelopeCodec(self::ENVELOPE_KEY),
        );
        $this->expectTransitionRollback(
            new CapabilityWorkflowPublisher(
                $this->notifications,
                $this->notificationContext(),
                $wrongTaskContext,
            ),
            (string) $started->instanceKey,
            'WORKFLOW_PROVIDER_UNAVAILABLE',
        );

        $receipt = $runtime->applyTransition(
            $definitionRead,
            (string) $started->instanceKey,
            'approve',
            1,
            self::SUBJECT_REVISION,
            'Approved',
            [],
            'workflow-composition-transition',
        );
        self::assertSame('completed', $receipt->instanceStatus);
        self::assertSame(NotificationPackage::RESOURCE_KEY, $publisher->lastNotificationContext?->resourceKey);
        self::assertSame('manage', $publisher->lastNotificationContext->operation);
        self::assertNotSame(
            $definitionRead->authorizationBasisDigest,
            $publisher->lastNotificationContext->authorizationBasisDigest,
        );
        self::assertSame(1, (int) $this->query('SELECT COUNT(*) FROM pa_notification_message')->fetchColumn());
        self::assertSame(1, (int) $this->query('SELECT COUNT(*) FROM pa_notification_outbox')->fetchColumn());
        self::assertSame(1, (int) $this->query('SELECT COUNT(*) FROM pa_task_job')->fetchColumn());
        self::assertSame(2, (int) $this->query('SELECT COUNT(*) FROM test_workflow_effect_child')->fetchColumn());
        $committedEffects = new WorkflowTransitionEffects(
            (string) $started->instanceKey,
            2,
            'approve',
            self::SUBJECT_REVISION,
            [new WorkflowNotificationIntent(self::TEMPLATE_KEY, 'actor')],
            [new WorkflowTaskIntent(self::TASK_TYPE)],
        );
        self::assertSame([
            $committedEffects->childKey('notification', 0),
            $committedEffects->childKey('task', 0),
        ], $this->query('SELECT child_key FROM test_workflow_effect_child ORDER BY child_key')->fetchAll(PDO::FETCH_COLUMN));

        $envelope = (new TrustedEnvelopeCodec(self::ENVELOPE_KEY))->verify(
            (string) $this->query('SELECT trusted_envelope FROM pa_task_job LIMIT 1')->fetchColumn(),
        );
        self::assertSame(self::SUBJECT_RESOURCE, $envelope->resourceKey);
        self::assertSame('approve', $envelope->operation);
        self::assertSame(self::SUBJECT_KEY, $envelope->requestedTargets[0]->targetIds[0] ?? null);

        $committed = $this->effectState();
        $publishCalls = $publisher->publishCalls;
        $authorizationCalls = $this->workflowAuthorization->decideTargetCalls;
        $replay = $runtime->applyTransition(
            $definitionRead,
            (string) $started->instanceKey,
            'approve',
            1,
            self::SUBJECT_REVISION,
            'Approved',
            [],
            'workflow-composition-transition',
        );
        self::assertSame($receipt->toArray(), $replay->toArray());
        self::assertSame($committed, $this->effectState());
        self::assertSame($publishCalls, $publisher->publishCalls);
        self::assertSame($authorizationCalls, $this->workflowAuthorization->decideTargetCalls);
    }

    public function testRealAuthorizationFailsClosedForMissingPermissionAndInvisibleTarget(): void
    {
        $basis = $this->definitionContext('read');
        $delete = $this->database->prepare(<<<'SQL'
DELETE role_permission
FROM pa_role_permission role_permission
INNER JOIN pa_permission permission ON permission.id = role_permission.permission_id
WHERE role_permission.tenant_id = :tenant_id AND permission.`key` = :permission_key
SQL);
        $delete->execute([
            'tenant_id' => $this->tenantId,
            'permission_key' => self::APPROVE_PERMISSION,
        ]);
        (new ThinkPhpAuthorizationRevisionRepository())->bumpRole($this->tenantId, $this->roleId);
        $freshAuthorization = new CapabilityWorkflowAuthorization(
            new TenantAuthorizationEvaluator(
                new ThinkPhpTenantAuthorizationRepository(),
                new RevisionPermissionCache(),
            ),
            $this->dataAuthorization,
        );
        try {
            $freshAuthorization->authorize(
                $basis,
                self::SUBJECT_RESOURCE,
                'approve',
                [self::APPROVE_PERMISSION],
                self::SUBJECT_KEY,
            );
            self::fail('The real functional evaluator must deny a removed permission.');
        } catch (ApiException $exception) {
            self::assertSame('AUTHZ_PERMISSION_DENIED', $exception->errorCode);
            self::assertSame(403, $exception->httpStatus);
        }

        $hide = $this->database->prepare(
            'UPDATE test_workflow_subject SET tenant_id = :other_tenant_id WHERE subject_key = :subject_key',
        );
        $hide->execute([
            'other_tenant_id' => $this->tenantId + 1000,
            'subject_key' => self::SUBJECT_KEY,
        ]);
        try {
            $freshAuthorization->authorize(
                $basis,
                self::SUBJECT_RESOURCE,
                'start',
                [self::START_PERMISSION],
                self::SUBJECT_KEY,
            );
            self::fail('A subject outside the trusted Tenant must remain invisible.');
        } catch (WorkflowException $exception) {
            self::assertSame('WORKFLOW_SUBJECT_NOT_FOUND', $exception->errorCode);
        }
    }

    private function runtime(CapabilityWorkflowPublisher $publisher): WorkflowRuntime
    {
        return new WorkflowRuntime(
            new ThinkPhpWorkflowRepository(),
            new IdempotencyService(),
            new AuditService(),
            new CapabilityWorkflowAssignments(),
            $this->workflowAuthorization,
            new CapabilitySubjectRevisionResolver(),
            new CapabilityWorkflowAttachments(),
            $publisher,
        );
    }

    private function publisher(?AuthorizedOperationContext $notificationContext): CapabilityWorkflowPublisher
    {
        return new CapabilityWorkflowPublisher(
            $notificationContext === null ? null : $this->notifications,
            $notificationContext,
            $this->tasks,
        );
    }

    private function expectTransitionRollback(
        CapabilityWorkflowPublisher $publisher,
        string $instanceKey,
        string $errorCode,
    ): void {
        $before = $this->effectState();
        try {
            $this->runtime($publisher)->applyTransition(
                $this->definitionContext('read'),
                $instanceKey,
                'approve',
                1,
                self::SUBJECT_REVISION,
                'Approved',
                [],
                'workflow-attempt-' . bin2hex(random_bytes(8)),
            );
            self::fail('The invalid capability composition must roll back.');
        } catch (WorkflowException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
        }
        self::assertSame($before, $this->effectState());
    }

    /** @return array<string, int|string> */
    private function effectState(): array
    {
        $state = [];
        foreach ([
            'pa_workflow_instance',
            'pa_workflow_work_item',
            'pa_workflow_event',
            'pa_tenant_audit_event',
            'pa_tenant_idempotency_record',
            'pa_notification_message',
            'pa_notification_outbox',
            'pa_notification_event',
            'pa_task_job',
            'pa_task_job_event',
            'test_workflow_effect_child',
        ] as $table) {
            $state[$table] = (int) $this->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        }
        $state['workflow_instance_state'] = (string) ($this->query(<<<'SQL'
SELECT CONCAT(status, ':', current_node_key, ':', revision)
FROM pa_workflow_instance ORDER BY id LIMIT 1
SQL)->fetchColumn() ?: '');
        $state['workflow_work_item_state'] = (string) ($this->query(<<<'SQL'
SELECT GROUP_CONCAT(CONCAT(status, ':', revision) ORDER BY id SEPARATOR ',')
FROM pa_workflow_work_item
SQL)->fetchColumn() ?: '');

        return $state;
    }

    /** @param list<string> $names */
    private function checkpointCount(array $names): int
    {
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $statement = $this->database->prepare(
            "SELECT COUNT(*) FROM test_workflow_atomicity WHERE checkpoint_name IN ({$placeholders})",
        );
        $statement->execute($names);

        return (int) $statement->fetchColumn();
    }

    private function definitionContext(string $operation): AuthorizedOperationContext
    {
        $permission = match ($operation) {
            'read' => WorkflowPackage::DEFINITION_READ_PERMISSION,
            'write' => WorkflowPackage::DEFINITION_WRITE_PERMISSION,
            'publish' => WorkflowPackage::DEFINITION_PUBLISH_PERMISSION,
            default => throw new RuntimeException('Unknown definition operation.'),
        };
        $this->functionalAuthorization->assertAllowed($this->tenantContext, $permission);

        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $this->tenantContext,
            WorkflowPackage::DEFINITION_RESOURCE_KEY,
            $operation,
            [],
            hash('sha256', "functional:{$permission}:{$this->memberId}"),
        ));
    }

    private function notificationContext(): AuthorizedOperationContext
    {
        $this->functionalAuthorization->assertAllowed(
            $this->tenantContext,
            NotificationPackage::MANAGE_PERMISSION,
        );

        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $this->tenantContext,
            NotificationPackage::RESOURCE_KEY,
            'manage',
            [],
            hash('sha256', 'notification-manage:' . $this->memberId),
        ));
    }

    /** @return array<string, mixed> */
    private function graph(): array
    {
        return [
            'contract_version' => 1,
            'subject_resource_key' => self::SUBJECT_RESOURCE,
            'subject_read_operation' => 'read',
            'subject_start_operation' => 'start',
            'start_permission_keys' => [self::START_PERMISSION],
            'nodes' => [
                ['key' => 'start', 'type' => 'start', 'completion_policy' => null, 'assignments' => []],
                ['key' => 'review', 'type' => 'review', 'completion_policy' => 'any', 'assignments' => [
                    ['kind' => 'role', 'key' => 'host.reviewer'],
                ]],
                ['key' => 'done', 'type' => 'terminal', 'completion_policy' => null, 'assignments' => []],
            ],
            'transitions' => [
                [
                    'key' => 'submit', 'from' => 'start', 'to' => 'review', 'operation' => 'start',
                    'action_kind' => 'advance', 'permission_keys' => [self::START_PERMISSION],
                    'human_required' => false, 'return_edge' => false, 'max_traversals' => null,
                    'notification_intent' => null, 'task_intent' => null,
                ],
                [
                    'key' => 'approve', 'from' => 'review', 'to' => 'done', 'operation' => 'approve',
                    'action_kind' => 'approve', 'permission_keys' => [self::APPROVE_PERMISSION],
                    'human_required' => true, 'return_edge' => false, 'max_traversals' => null,
                    'notification_intent' => [
                        'template_key' => self::TEMPLATE_KEY,
                        'recipient_rule' => 'actor',
                    ],
                    'task_intent' => ['task_type' => self::TASK_TYPE],
                ],
            ],
        ];
    }

    private function createHostFixtureTables(): void
    {
        $this->database->exec(<<<'SQL'
CREATE TABLE test_workflow_subject (
  subject_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  revision_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  revision_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (subject_key),
  KEY idx_test_workflow_subject_tenant (tenant_id, subject_key)
) ENGINE=InnoDB
SQL);
        $this->database->exec(<<<'SQL'
CREATE TABLE test_workflow_effect_child (
  child_key VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  effect_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (child_key)
) ENGINE=InnoDB
SQL);
        $this->database->exec(<<<'SQL'
CREATE TABLE test_workflow_atomicity (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  checkpoint_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB
SQL);
    }

    private function seedAuthorities(): void
    {
        $this->catalog = new ThinkPhpAuthorizationCatalogRepository();
        $this->accountId = $this->insert('pa_account', [
            'display_name' => 'Workflow reviewer',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->tenantId = $this->insert('pa_tenant', [
            'code' => 'workflow-capability',
            'name' => 'Workflow capability',
            'display_name' => 'Workflow capability',
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->memberId = $this->insert('pa_tenant_member', [
            'tenant_id' => $this->tenantId,
            'account_id' => $this->accountId,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        foreach ([WorkflowPackage::MODULE_KEY, self::HOST_MODULE, NotificationPackage::MODULE_KEY] as $module) {
            $this->insert('pa_module_installation', [
                'module_key' => $module,
                'installed_version' => '1.0.0',
                'manifest_schema_version' => 1,
                'manifest_digest' => hash('sha256', $module),
                'status' => 'active',
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
            $this->insert('pa_tenant_module', [
                'tenant_id' => $this->tenantId,
                'module_key' => $module,
                'status' => 'enabled',
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
        }
        $permissions = [];
        foreach ([
            [WorkflowPackage::DEFINITION_READ_PERMISSION, WorkflowPackage::MODULE_KEY],
            [WorkflowPackage::DEFINITION_WRITE_PERMISSION, WorkflowPackage::MODULE_KEY],
            [WorkflowPackage::DEFINITION_PUBLISH_PERMISSION, WorkflowPackage::MODULE_KEY],
            [WorkflowPackage::INSTANCE_START_PERMISSION, WorkflowPackage::MODULE_KEY],
            [WorkflowPackage::INSTANCE_TRANSITION_PERMISSION, WorkflowPackage::MODULE_KEY],
            [self::START_PERMISSION, self::HOST_MODULE],
            [self::APPROVE_PERMISSION, self::HOST_MODULE],
            [NotificationPackage::MANAGE_PERMISSION, NotificationPackage::MODULE_KEY],
        ] as [$key, $module]) {
            $permissions[$key] = $this->catalog->syncPermission(new PermissionDefinition(
                $key,
                $module,
                'api',
                $key,
                'sensitive',
                '1.0.0',
            ));
        }
        $this->roleId = $this->insert('pa_role', [
            'tenant_id' => $this->tenantId,
            'key' => 'host.reviewer',
            'name' => 'Workflow reviewer',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_member_role', [
            'tenant_id' => $this->tenantId,
            'tenant_member_id' => $this->memberId,
            'role_id' => $this->roleId,
            'assigned_at' => self::NOW,
        ]);
        foreach ($permissions as $permissionId) {
            $this->insert('pa_role_permission', [
                'tenant_id' => $this->tenantId,
                'role_id' => $this->roleId,
                'permission_id' => $permissionId,
                'granted_at' => self::NOW,
            ]);
        }
        $this->catalog->syncProtectedResource(new ProtectedResourceDefinition(
            self::SUBJECT_RESOURCE,
            self::HOST_MODULE,
            'Workflow subject',
            'tenant_owned',
            'host.workflow.subject.policy',
            '1.0.0',
            hash('sha256', 'workflow-subject-resource'),
        ));
        $targetTypeId = $this->catalog->syncTargetType(new TargetTypeDefinition(
            self::SUBJECT_RESOURCE,
            self::HOST_MODULE,
            'Workflow subject',
            'host.workflow.subject.resolver',
            'host.workflow.subject.catalog',
            'string',
            '1.0.0',
            hash('sha256', 'workflow-subject-target'),
        ));
        $startPermission = $permissions[self::START_PERMISSION] ?? throw new LogicException('Start permission fixture is missing.');
        $approvePermission = $permissions[self::APPROVE_PERMISSION] ?? throw new LogicException('Approve permission fixture is missing.');
        foreach ([
            ['start', $startPermission],
            ['approve', $approvePermission],
        ] as [$operation, $permissionId]) {
            $operationId = $this->catalog->syncResourceOperation(new ResourceOperationDefinition(
                self::SUBJECT_RESOURCE,
                $operation,
                'tenant_wide',
                'one_required',
                'all',
                'deny_and_write',
                hash('sha256', 'workflow-subject-' . $operation),
            ));
            $this->catalog->bindOperationPermission($operationId, $permissionId);
            $this->catalog->bindOperationTargetType($operationId, $targetTypeId, 'primary', 'explicit', $permissionId);
        }
        $subject = $this->database->prepare(<<<'SQL'
INSERT INTO test_workflow_subject (subject_key, tenant_id, revision_key, revision_sha256)
VALUES (:subject_key, :tenant_id, :revision_key, :revision_sha256)
SQL);
        $subject->execute([
            'subject_key' => self::SUBJECT_KEY,
            'tenant_id' => $this->tenantId,
            'revision_key' => self::SUBJECT_REVISION,
            'revision_sha256' => hash('sha256', self::SUBJECT_REVISION),
        ]);
        $this->tenantContext = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            '01J00000000000000000000000',
            $this->tenantId,
            $this->accountId,
            $this->memberId,
            'admin-web',
            new DateTimeImmutable('2026-08-11T08:00:00.000Z'),
            1,
        ), 'req_workflow_capability');
        $this->functionalAuthorization = new TenantAuthorizationEvaluator(
            new ThinkPhpTenantAuthorizationRepository(),
            new RevisionPermissionCache(),
        );
        $resolvers = new TargetResolverRegistry();
        $resolvers->register(
            'host.workflow.subject.resolver',
            new CapabilitySubjectTargetResolver(),
        );
        $this->dataAuthorization = new DataPermissionEngine(
            new ThinkPhpResourceOperationCatalog(),
            new ThinkPhpPolicyRepository(),
            new PolicyCache(),
            $this->functionalAuthorization,
            new ResourceProviderRegistry(),
            $resolvers,
            new TargetCatalogProviderRegistry(),
            new SharedMasterScopeProviderRegistry(),
        );
        $this->workflowAuthorization = new CapabilityWorkflowAuthorization(
            $this->functionalAuthorization,
            $this->dataAuthorization,
        );
    }

}

final class CapabilityWorkflowAuthorization implements WorkflowAuthorizationResolver
{
    public int $decideTargetCalls = 0;

    public function __construct(
        private readonly TenantAuthorizationEvaluator $functional,
        private readonly DataPermissionEngine $data,
    ) {}

    public function authorize(
        AuthorizedOperationContext $trustedBasis,
        string $resourceKey,
        string $operation,
        array $permissionKeys,
        string $subjectKey,
    ): AuthorizedOperationContext {
        try {
            foreach ($permissionKeys as $permissionKey) {
                $this->functional->assertAllowed($trustedBasis->tenantContext, $permissionKey);
            }
            $targets = new TypedResourceTargetCollection([
                new TypedResourceTargetSet($resourceKey, [$subjectKey]),
            ]);
            ++$this->decideTargetCalls;
            $decision = $this->data->decideTargets(
                $trustedBasis->tenantContext,
                $resourceKey,
                $operation,
                $targets,
            );
        } catch (AuthorizationException $exception) {
            throw new ApiException($exception->errorCode, 403, 'The operation is not permitted.');
        } catch (DataAuthorizationException $exception) {
            throw WorkflowException::subjectNotFound();
        }
        if (!$decision->allowed) {
            throw WorkflowException::subjectNotFound();
        }

        // Issue the context only after the real functional and typed-target authorities both allow it.
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $trustedBasis->tenantContext,
            $resourceKey,
            $operation,
            [new RequestedTargetSet($resourceKey, [$subjectKey])],
            hash('sha256', implode('|', [
                $trustedBasis->authorizationBasisDigest,
                $resourceKey,
                $operation,
                implode(',', $permissionKeys),
                $decision->reasonCode,
                $subjectKey,
            ])),
        ));
    }
}

final readonly class CapabilitySubjectTargetResolver implements ResourceTargetResolver
{
    public function resolveAndValidate(
        TenantContext $context,
        TypedResourceTargetSet $targets,
    ): ResolvedResourceTargets {
        if ($targets->targetResourceKey !== WorkflowCapabilityCompositionTest::SUBJECT_RESOURCE
            || $targets->targetRole !== 'primary'
            || count($targets->targetIds) !== 1
        ) {
            throw WorkflowException::subjectNotFound();
        }
        $subjectKey = Db::table('test_workflow_subject')
            ->where('tenant_id', $context->tenantId)
            ->where('subject_key', $targets->targetIds[0])
            ->value('subject_key');
        if ($subjectKey === null) {
            throw WorkflowException::subjectNotFound();
        }

        return new ResolvedResourceTargets(new TypedResourceTargetCollection([$targets]));
    }
}

final readonly class CapabilityWorkflowAssignments implements WorkflowAssignmentResolver
{
    public function resolve(
        AuthorizedOperationContext $context,
        array $rules,
        int $initiatorMemberId,
        ?int $previousActorMemberId,
    ): array {
        $rule = $rules[0];
        if ($rule !== ['kind' => 'role', 'key' => 'host.reviewer']) {
            throw WorkflowException::assignmentDenied();
        }
        $memberIds = TenantMember::alias('member')
            ->join(
                'member_role membership',
                'membership.tenant_id = member.tenant_id AND membership.tenant_member_id = member.id',
            )
            ->join(
                'role role',
                'role.tenant_id = membership.tenant_id AND role.id = membership.role_id',
            )
            ->where('member.tenant_id', $context->tenantContext->tenantId)
            ->where('member.status', 'active')
            ->where('role.key', 'host.reviewer')
            ->where('role.status', 'active')
            ->order('member.id')
            ->column('member.id');

        return array_values(array_map(
            static fn(string|int $memberId): array => [
                'source_kind' => 'role',
                'source_key' => 'host.reviewer',
                'member_id' => (int) $memberId,
            ],
            $memberIds,
        ));
    }
}

final readonly class CapabilitySubjectRevisionResolver implements WorkflowSubjectRevisionResolver
{
    public function resolve(
        AuthorizedOperationContext $context,
        string $subjectType,
        string $subjectKey,
        string $expectedRevisionKey,
    ): array {
        if ($subjectType !== WorkflowCapabilityCompositionTest::SUBJECT_RESOURCE) {
            throw WorkflowException::subjectNotFound();
        }
        $row = Db::table('test_workflow_subject')
            ->where('tenant_id', $context->tenantContext->tenantId)
            ->where('subject_key', $subjectKey)
            ->where('revision_key', $expectedRevisionKey)
            ->field('revision_key,revision_sha256')
            ->find();
        if ($row === null) {
            throw WorkflowException::subjectRevisionConflict();
        }

        return ['revision_key' => (string) $row['revision_key'], 'sha256' => (string) $row['revision_sha256']];
    }
}

final readonly class CapabilityWorkflowAttachments implements WorkflowAttachmentResolver
{
    public function snapshot(AuthorizedOperationContext $context, string $fileKey): WorkflowAttachment
    {
        throw WorkflowException::attachmentUnavailable();
    }
}

final class CapabilityWorkflowPublisher implements WorkflowSideEffectPublisher
{
    public int $publishCalls = 0;
    public ?AuthorizedOperationContext $lastNotificationContext = null;

    public function __construct(
        private readonly ?NotificationService $notifications,
        private readonly ?AuthorizedOperationContext $notificationContext,
        private readonly TrustedJobPublisher $tasks,
    ) {}

    public function publish(
        AuthorizedOperationContext $context,
        WorkflowTransitionEffects $effects,
        string $parentIdempotencyKey,
    ): void {
        ++$this->publishCalls;
        try {
            foreach ($effects->notificationIntents as $index => $intent) {
                if (!$this->notifications instanceof NotificationService
                    || !$this->notificationContext instanceof AuthorizedOperationContext
                    || $this->notificationContext->tenantContext !== $context->tenantContext
                    || $this->notificationContext->resourceKey !== NotificationPackage::RESOURCE_KEY
                    || $this->notificationContext->operation !== 'manage'
                ) {
                    throw WorkflowException::providerUnavailable();
                }
                if (!$this->reserveChild(
                    $effects->childKey('notification', $index),
                    'notification',
                    $effects->requestHash($intent),
                )) {
                    continue;
                }
                $this->lastNotificationContext = $this->notificationContext;
                $this->notifications->publish(
                    $this->notificationContext,
                    $intent->templateKey,
                    [['member_id' => $context->tenantContext->memberId, 'variables' => []]],
                );
            }
            foreach ($effects->taskIntents as $index => $intent) {
                if (!$this->reserveChild(
                    $effects->childKey('task', $index),
                    'task',
                    $effects->requestHash($intent),
                )) {
                    continue;
                }
                $this->tasks->publish(
                    $context,
                    $intent->taskType,
                    [
                        'instance_key' => $effects->instanceKey,
                        'event_sequence' => $effects->eventSequence,
                        'transition_key' => $effects->transitionKey,
                        'subject_revision_key' => $effects->subjectRevisionKey,
                    ],
                    $effects->childKey('task', $index),
                );
            }
        } catch (WorkflowException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw WorkflowException::providerUnavailable();
        }
    }

    private function reserveChild(string $childKey, string $kind, string $requestHash): bool
    {
        try {
            Db::table('test_workflow_effect_child')->insert([
                'child_key' => $childKey,
                'effect_kind' => $kind,
                'request_hash' => $requestHash,
            ]);

            return true;
        } catch (ThinkPhpPDOException $exception) {
            $error = $exception->getData()['PDO Error Info'] ?? [];
            if (($error['SQLSTATE'] ?? null) !== '23000'
                || (int) ($error['Driver Error Code'] ?? 0) !== 1062
            ) {
                throw $exception;
            }
        }
        $row = Db::table('test_workflow_effect_child')
            ->where('child_key', $childKey)
            ->field('effect_kind,request_hash')
            ->find();
        if ($row === null
            || !hash_equals((string) $row['effect_kind'], $kind)
            || !hash_equals((string) $row['request_hash'], $requestHash)
        ) {
            throw WorkflowException::providerUnavailable();
        }

        return false;
    }
}

final readonly class CapabilityTaskSubmissionProvider implements TaskSubmissionProvider
{
    public function taskType(): string
    {
        return WorkflowCapabilityCompositionTest::TASK_TYPE;
    }

    public function resourceKey(): string
    {
        return WorkflowCapabilityCompositionTest::SUBJECT_RESOURCE;
    }

    public function operation(): string
    {
        return 'approve';
    }

    public function build(AuthorizedOperationContext $context, array $input): TaskSubmission
    {
        return new TaskSubmission('workflow.finalize', $input);
    }
}

final readonly class MismatchedCapabilityTaskSubmissionProvider implements TaskSubmissionProvider
{
    public function taskType(): string
    {
        return WorkflowCapabilityCompositionTest::TASK_TYPE;
    }

    public function resourceKey(): string
    {
        return WorkflowCapabilityCompositionTest::SUBJECT_RESOURCE;
    }

    public function operation(): string
    {
        return 'finalize';
    }

    public function build(AuthorizedOperationContext $context, array $input): TaskSubmission
    {
        return new TaskSubmission('workflow.finalize', $input);
    }
}

final readonly class CapabilityRecipientResolver implements RecipientResolver
{
    public function snapshot(TenantContext $context, int $memberId, bool $requiresSms): RecipientSnapshot
    {
        if ($requiresSms) {
            throw NotificationException::recipientUnavailable();
        }
        $row = TenantMember::alias('member')
            ->join('account account', 'account.id = member.account_id')
            ->where('member.tenant_id', $context->tenantId)
            ->where('member.id', $memberId)
            ->where('member.status', 'active')
            ->field('member.account_id,account.display_name')
            ->find();
        if ($row === null) {
            throw NotificationException::recipientUnavailable();
        }

        return new RecipientSnapshot(
            $memberId,
            (int) $row['account_id'],
            (string) $row['display_name'],
            null,
            null,
        );
    }
}

final readonly class CapabilityNotificationAttachments implements NotificationAttachmentResolver
{
    public function snapshot(TenantContext $context, string $fileKey): AttachmentReference
    {
        throw NotificationException::attachmentUnavailable();
    }
}
