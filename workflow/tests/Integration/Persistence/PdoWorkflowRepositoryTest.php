<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Tests\Integration\Persistence;

use PDO;
use PeanutAdmin\Workflow\Application\WorkflowException;
use PeanutAdmin\Workflow\Database\Schema;
use PeanutAdmin\Workflow\Definition\WorkflowGraph;
use PeanutAdmin\Workflow\Persistence\PdoWorkflowRepository;
use PeanutAdmin\Workflow\Tests\Unit\Definition\WorkflowGraphTest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PdoWorkflowRepositoryTest extends TestCase
{
    private const DATABASE = 'peanut_admin_p1_wf01_repository_test';

    private PDO $admin;
    private PDO $pdo;

    protected function setUp(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through P1-WORKFLOW-RUNTIME-001 MySQL qualification.');
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
        $this->pdo->exec('CREATE TABLE pa_tenant (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB');
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

    public function testUpgradesAnAlpha2BaselineAndCreateSqlCanReenter(): void
    {
        foreach (array_reverse(Schema::tableNames()) as $table) {
            $this->pdo->exec(Schema::dropSql($table));
        }
        $this->pdo->exec('INSERT INTO pa_tenant VALUES ()');
        $tenantId = (int) $this->pdo->lastInsertId();

        foreach (Schema::createSql() as $statement) {
            $this->pdo->exec($statement);
        }
        foreach (Schema::createSql() as $statement) {
            $this->pdo->exec($statement);
        }

        self::assertSame($tenantId, (int) $this->pdo->query('SELECT id FROM pa_tenant')->fetchColumn());
        self::assertSame(Schema::tableNames(), $this->workflowTables());
    }

    public function testInformationSchemaMatchesExactColumnsGeneratedColumnsAndIndexes(): void
    {
        self::assertSame([
            'pa_workflow_definition:id,tenant_id,module_key,workflow_key,status,draft_graph_json,draft_graph_sha256,latest_version,revision,created_by_member_id,updated_by_member_id,created_at,updated_at,retired_at',
            'pa_workflow_definition_version:id,tenant_id,definition_id,version,graph_json,graph_sha256,published_by_member_id,published_at',
            'pa_workflow_event:id,tenant_id,instance_id,sequence_no,event_key,transition_key,from_node_key,to_node_key,actor_type,actor_member_id,subject_revision_key,subject_revision_sha256,comment_text,comment_sha256,attachment_snapshots_json,metadata_json,occurred_at',
            'pa_workflow_instance:id,instance_key,tenant_id,definition_id,definition_version,subject_type,subject_key,subject_revision_key,subject_revision_sha256,current_node_key,status,initiated_by_member_id,last_actor_member_id,revision,created_at,updated_at,completed_at,cancelled_at,active_marker',
            'pa_workflow_work_item:id,work_item_key,tenant_id,instance_id,node_key,round_no,assignment_source_kind,assignment_source_key,assignee_member_id,status,decision,completed_by_member_id,revision,created_at,updated_at,completed_at,cancelled_at,pending_marker',
        ], $this->columnSignatures());
        self::assertSame([
            'pa_workflow_instance:active_marker:STORED GENERATED:active',
            'pa_workflow_work_item:pending_marker:STORED GENERATED:pending',
        ], $this->generatedColumnSignatures());
        self::assertSame([
            'pa_workflow_definition:PRIMARY:unique:id',
            'pa_workflow_definition:idx_workflow_definition_status:non-unique:tenant_id,status,id',
            'pa_workflow_definition:uk_workflow_definition_identity:unique:tenant_id,module_key,workflow_key',
            'pa_workflow_definition:uk_workflow_definition_tenant_id:unique:tenant_id,id',
            'pa_workflow_definition_version:PRIMARY:unique:id',
            'pa_workflow_definition_version:idx_workflow_definition_version_published:non-unique:tenant_id,published_at,id',
            'pa_workflow_definition_version:uk_workflow_definition_version_digest:unique:tenant_id,definition_id,graph_sha256',
            'pa_workflow_definition_version:uk_workflow_definition_version_number:unique:tenant_id,definition_id,version',
            'pa_workflow_definition_version:uk_workflow_definition_version_tenant_id:unique:tenant_id,id',
            'pa_workflow_event:PRIMARY:unique:id',
            'pa_workflow_event:idx_workflow_event_instance_time:non-unique:tenant_id,instance_id,occurred_at,id',
            'pa_workflow_event:idx_workflow_event_key_time:non-unique:tenant_id,event_key,occurred_at,id',
            'pa_workflow_event:uk_workflow_event_sequence:unique:tenant_id,instance_id,sequence_no',
            'pa_workflow_instance:PRIMARY:unique:id',
            'pa_workflow_instance:fk_workflow_instance_version:non-unique:tenant_id,definition_id,definition_version',
            'pa_workflow_instance:idx_workflow_instance_status:non-unique:tenant_id,status,updated_at,id',
            'pa_workflow_instance:idx_workflow_instance_subject:non-unique:tenant_id,subject_type,subject_key,id',
            'pa_workflow_instance:uk_workflow_instance_active_subject:unique:tenant_id,definition_id,subject_type,subject_key,active_marker',
            'pa_workflow_instance:uk_workflow_instance_key:unique:instance_key',
            'pa_workflow_instance:uk_workflow_instance_tenant_id:unique:tenant_id,id',
            'pa_workflow_work_item:PRIMARY:unique:id',
            'pa_workflow_work_item:idx_workflow_work_item_assignee:non-unique:tenant_id,assignee_member_id,status,created_at,id',
            'pa_workflow_work_item:idx_workflow_work_item_instance:non-unique:tenant_id,instance_id,status,id',
            'pa_workflow_work_item:uk_workflow_work_item_key:unique:work_item_key',
            'pa_workflow_work_item:uk_workflow_work_item_pending_assignee:unique:tenant_id,instance_id,node_key,round_no,assignee_member_id,pending_marker',
            'pa_workflow_work_item:uk_workflow_work_item_tenant_id:unique:tenant_id,id',
        ], $this->indexSignatures());
    }

    public function testInformationSchemaMatchesExactForeignKeysAndChecks(): void
    {
        self::assertSame([
            'pa_workflow_definition:fk_workflow_definition_tenant:tenant_id:pa_tenant:id:RESTRICT',
            'pa_workflow_definition_version:fk_workflow_definition_version_definition:tenant_id,definition_id:pa_workflow_definition:tenant_id,id:RESTRICT',
            'pa_workflow_event:fk_workflow_event_instance:tenant_id,instance_id:pa_workflow_instance:tenant_id,id:RESTRICT',
            'pa_workflow_instance:fk_workflow_instance_tenant:tenant_id:pa_tenant:id:RESTRICT',
            'pa_workflow_instance:fk_workflow_instance_version:tenant_id,definition_id,definition_version:pa_workflow_definition_version:tenant_id,definition_id,version:RESTRICT',
            'pa_workflow_work_item:fk_workflow_work_item_instance:tenant_id,instance_id:pa_workflow_instance:tenant_id,id:RESTRICT',
        ], $this->foreignKeySignatures());
        self::assertSame([
            'pa_workflow_definition:chk_workflow_definition_digest',
            'pa_workflow_definition:chk_workflow_definition_module_key',
            'pa_workflow_definition:chk_workflow_definition_retired_shape',
            'pa_workflow_definition:chk_workflow_definition_revision',
            'pa_workflow_definition:chk_workflow_definition_status',
            'pa_workflow_definition:chk_workflow_definition_workflow_key',
            'pa_workflow_definition_version:chk_workflow_definition_version_digest',
            'pa_workflow_definition_version:chk_workflow_definition_version_number',
            'pa_workflow_event:chk_workflow_event_actor_shape',
            'pa_workflow_event:chk_workflow_event_comment_shape',
            'pa_workflow_event:chk_workflow_event_key',
            'pa_workflow_event:chk_workflow_event_sequence',
            'pa_workflow_event:chk_workflow_event_subject_digest',
            'pa_workflow_instance:chk_workflow_instance_digest',
            'pa_workflow_instance:chk_workflow_instance_key',
            'pa_workflow_instance:chk_workflow_instance_revision',
            'pa_workflow_instance:chk_workflow_instance_status',
            'pa_workflow_instance:chk_workflow_instance_terminal_shape',
            'pa_workflow_instance:chk_workflow_instance_version',
            'pa_workflow_work_item:chk_workflow_work_item_key',
            'pa_workflow_work_item:chk_workflow_work_item_revision',
            'pa_workflow_work_item:chk_workflow_work_item_round',
            'pa_workflow_work_item:chk_workflow_work_item_source',
            'pa_workflow_work_item:chk_workflow_work_item_status',
            'pa_workflow_work_item:chk_workflow_work_item_terminal_shape',
        ], $this->checkConstraintSignatures());
    }

    public function testMysqlJsonNormalizationUsesThePersistedCanonicalDigest(): void
    {
        $this->pdo->exec('INSERT INTO pa_tenant VALUES ()');
        $tenantId = (int) $this->pdo->lastInsertId();
        $repository = new PdoWorkflowRepository($this->pdo);
        $graph = WorkflowGraph::fromArray(WorkflowGraphTest::validGraph());
        $now = '2026-08-11 00:00:00.000';
        $draft = $repository->saveDraft($tenantId, 11, 'module.sample', 'approval', $graph, null, $now);
        $published = $repository->publishDefinition(
            $tenantId,
            11,
            'module.sample',
            'approval',
            $draft->revision,
            $now,
        );

        self::assertSame($graph->sha256, $draft->draftGraph()->sha256);
        self::assertSame($graph->sha256, $published['version']->graph()->sha256);

        $this->pdo->exec("UPDATE pa_workflow_definition SET draft_graph_sha256 = REPEAT('0', 64)");
        $this->assertInternalError(
            fn() => $repository->definition($tenantId, 'module.sample', 'approval')?->draftGraph(),
        );
        $this->pdo->exec("UPDATE pa_workflow_definition_version SET graph_sha256 = REPEAT('0', 64)");
        $this->assertInternalError(
            fn() => $repository->definitionVersion($tenantId, $published['definition']->id, 1)?->graph(),
        );
    }

    public function testPersistsImmutableVersionPinnedInstanceWorkItemsAndEvents(): void
    {
        $this->pdo->exec('INSERT INTO pa_tenant VALUES ()');
        $tenantId = (int) $this->pdo->lastInsertId();
        $repository = new PdoWorkflowRepository($this->pdo);
        $graph = WorkflowGraph::fromArray(WorkflowGraphTest::validGraph());
        $now = '2026-08-11 00:00:00.000';

        $draft = $repository->saveDraft($tenantId, 11, 'module.sample', 'approval', $graph, null, $now);
        self::assertSame('draft', $draft->status);
        $published = $repository->publishDefinition(
            $tenantId,
            11,
            'module.sample',
            'approval',
            $draft->revision,
            $now,
        );
        self::assertSame(1, $published['version']->version);
        self::assertSame($graph->sha256, $published['version']->graphSha256);

        $instance = $repository->createInstance(
            $tenantId,
            $published['definition']->id,
            1,
            'instance_' . str_repeat('a', 32),
            'subject.item',
            'subject-1',
            'revision-1',
            str_repeat('b', 64),
            'review',
            11,
            $now,
        );
        $items = $repository->createWorkItems($tenantId, $instance->id, 'review', 1, [[
            'work_item_key' => 'work_' . str_repeat('c', 32),
            'source_kind' => 'role',
            'source_key' => 'reviewer',
            'member_id' => 12,
        ]], $now);
        $event = $repository->appendEvent(
            $tenantId,
            $instance->id,
            1,
            'tenant.workflow.instance_started',
            'submit',
            'start',
            'review',
            'member',
            11,
            'revision-1',
            str_repeat('b', 64),
            null,
            '[]',
            '{}',
            $now,
        );

        self::assertCount(1, $items);
        self::assertSame(1, $event->sequenceNo);
        self::assertSame(2, $repository->nextEventSequence($tenantId, $instance->id));
        self::assertNull($repository->instance($tenantId + 1, $instance->instanceKey));

        $nextGraphInput = WorkflowGraphTest::validGraph();
        $nextGraphInput['nodes'][3]['key'] = 'done-v2';
        $nextGraphInput['transitions'][2]['to'] = 'done-v2';
        $nextGraph = WorkflowGraph::fromArray($nextGraphInput);
        $updatedDraft = $repository->saveDraft(
            $tenantId,
            11,
            'module.sample',
            'approval',
            $nextGraph,
            $published['definition']->revision,
            $now,
        );
        $secondVersion = $repository->publishDefinition(
            $tenantId,
            11,
            'module.sample',
            'approval',
            $updatedDraft->revision,
            $now,
        );
        self::assertSame(2, $secondVersion['version']->version);
        self::assertSame(1, $repository->instance($tenantId, $instance->instanceKey)?->definitionVersion);
        self::assertSame(
            'done',
            $repository->definitionVersion($tenantId, $published['definition']->id, 1)?->graph()->node('done')->key,
        );
    }

    /** @return list<string> */
    private function workflowTables(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT table_name
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name LIKE 'pa_workflow_%'
ORDER BY FIELD(
  table_name,
  'pa_workflow_definition',
  'pa_workflow_definition_version',
  'pa_workflow_instance',
  'pa_workflow_work_item',
  'pa_workflow_event'
)
SQL);
        self::assertNotFalse($statement);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<string> */
    private function columnSignatures(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT table_name, GROUP_CONCAT(column_name ORDER BY ordinal_position SEPARATOR ',') AS columns
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name LIKE 'pa_workflow_%'
GROUP BY table_name
ORDER BY table_name
SQL);
        self::assertNotFalse($statement);

        return array_map(
            static fn(array $row): string => (string) $row['table_name'] . ':' . (string) $row['columns'],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /** @return list<string> */
    private function generatedColumnSignatures(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT table_name, column_name, extra, generation_expression
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name LIKE 'pa_workflow_%'
  AND generation_expression <> ''
ORDER BY table_name, ordinal_position
SQL);
        self::assertNotFalse($statement);

        return array_map(static function (array $row): string {
            $expression = strtolower((string) $row['generation_expression']);
            $state = str_contains($expression, 'active') ? 'active' : 'pending';
            if (!str_contains($expression, 'status')
                || !str_contains($expression, $state)
                || !str_contains($expression, 'then 1')
                || !str_contains($expression, 'else null')) {
                $state = 'invalid';
            }

            return implode(':', [
                (string) $row['table_name'],
                (string) $row['column_name'],
                (string) $row['extra'],
                $state,
            ]);
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<string> */
    private function indexSignatures(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT table_name, index_name, non_unique,
       GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name LIKE 'pa_workflow_%'
GROUP BY table_name, index_name, non_unique
ORDER BY table_name, index_name
SQL);
        self::assertNotFalse($statement);

        $signatures = array_map(
            static fn(array $row): string => implode(':', [
                (string) $row['table_name'],
                (string) $row['index_name'],
                (int) $row['non_unique'] === 0 ? 'unique' : 'non-unique',
                (string) $row['columns'],
            ]),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
        sort($signatures, SORT_STRING);

        return $signatures;
    }

    /** @return list<string> */
    private function foreignKeySignatures(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT k.table_name, k.constraint_name,
       GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',') AS columns,
       k.referenced_table_name,
       GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',') AS referenced_columns,
       r.delete_rule
FROM information_schema.key_column_usage k
JOIN information_schema.referential_constraints r
  ON r.constraint_schema = k.constraint_schema
 AND r.constraint_name = k.constraint_name
WHERE k.constraint_schema = DATABASE() AND k.table_name LIKE 'pa_workflow_%'
GROUP BY k.table_name, k.constraint_name, k.referenced_table_name, r.delete_rule
ORDER BY k.table_name, k.constraint_name
SQL);
        self::assertNotFalse($statement);

        return array_map(
            static fn(array $row): string => implode(':', array_map('strval', $row)),
            $statement->fetchAll(PDO::FETCH_NUM),
        );
    }

    /** @return list<string> */
    private function checkConstraintSignatures(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT tc.table_name, tc.constraint_name, cc.check_clause
FROM information_schema.table_constraints tc
JOIN information_schema.check_constraints cc
  ON cc.constraint_schema = tc.constraint_schema
 AND cc.constraint_name = tc.constraint_name
WHERE tc.constraint_schema = DATABASE()
  AND tc.table_name LIKE 'pa_workflow_%'
  AND tc.constraint_type = 'CHECK'
ORDER BY tc.table_name, tc.constraint_name
SQL);
        self::assertNotFalse($statement);

        return array_map(static function (array $row): string {
            $signature = (string) $row['table_name'] . ':' . (string) $row['constraint_name'];

            return trim((string) $row['check_clause']) === '' ? $signature . ':missing-clause' : $signature;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function assertInternalError(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected persisted workflow corruption to fail closed.');
        } catch (WorkflowException $exception) {
            self::assertSame('INTERNAL_ERROR', $exception->errorCode);
        }
    }
}
