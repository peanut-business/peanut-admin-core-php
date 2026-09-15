<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Tests\Unit\Workflow;

use LogicException;
use PDO;
use PeanutAdmin\App\Tests\Support\ThinkPhpTestConnection;
use PeanutAdmin\Testing\Workflow\WorkflowAtomicityContractHarness;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use Throwable;

final class WorkflowAtomicityContractHarnessTest extends TestCase
{
    public function testInjectsEverySelectedWorkflowCheckpointAndRequiresCommittedSuccess(): void
    {
        $state = ['workflow' => 0, 'audit' => 0, 'notification' => 0, 'task' => 0, 'idempotency' => 0];
        $checkpoints = [
            'instance_written', 'work_item_written', 'event_written', 'audit_written',
            'notification_written', 'task_written', 'idempotency_completed',
        ];
        ThinkPhpTestConnection::fromPdo($this->connection());
        $operation = static function (callable $checkpoint) use (&$state): void {
            Db::transaction(function () use (&$state, $checkpoint): void {
                ++$state['workflow'];
                $checkpoint('instance_written');
                ++$state['workflow'];
                $checkpoint('work_item_written');
                ++$state['workflow'];
                $checkpoint('event_written');
                ++$state['audit'];
                $checkpoint('audit_written');
                ++$state['notification'];
                $checkpoint('notification_written');
                ++$state['task'];
                $checkpoint('task_written');
                ++$state['idempotency'];
                $checkpoint('idempotency_completed');
            });
        };
        $operation = static function (callable $checkpoint) use (&$state, $operation): void {
            $before = $state;
            try {
                $operation($checkpoint);
            } catch (Throwable $exception) {
                $state = $before;
                throw $exception;
            }
        };
        $probes = [];
        foreach (array_keys($state) as $name) {
            $probes[$name] = static function () use (&$state, $name): int {
                return $state[$name];
            };
        }

        (new WorkflowAtomicityContractHarness())->assertAtomic(
            $operation,
            $probes,
            ['workflow' => 3, 'audit' => 1, 'notification' => 1, 'task' => 1, 'idempotency' => 1],
            $checkpoints,
        );
        self::addToAssertionCount(1);
    }

    public function testRejectsOutOfOrderCheckpointSelection(): void
    {
        ThinkPhpTestConnection::fromPdo($this->connection());
        $this->expectException(LogicException::class);
        (new WorkflowAtomicityContractHarness())->assertAtomic(
            static fn(callable $checkpoint) => null,
            ['workflow' => static fn(): int => 0],
            ['workflow' => 0],
            ['audit_written', 'event_written'],
        );
    }

    public function testRejectsAnOperationThatSwallowsTheInjectedFailure(): void
    {
        ThinkPhpTestConnection::fromPdo($this->connection());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('swallowed injected failure');

        (new WorkflowAtomicityContractHarness())->assertAtomic(
            static function (callable $checkpoint): void {
                Db::transaction(function () use ($checkpoint): void {
                    try {
                        $checkpoint('definition_written');
                    } catch (Throwable) {
                        // Deliberately invalid fixture: commit without propagation is not evidence.
                    }
                });
            },
            ['definition' => static fn(): int => 0],
            ['definition' => 0],
            ['definition_written'],
        );
    }

    public function testRejectsCheckpointOutsideTheThinkPhpTransaction(): void
    {
        ThinkPhpTestConnection::fromPdo($this->connection());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('outside the ThinkPHP transaction');

        (new WorkflowAtomicityContractHarness())->assertAtomic(
            static function (callable $checkpoint): void {
                $checkpoint('definition_written');
            },
            ['definition' => static fn(): int => 0],
            ['definition' => 0],
            ['definition_written'],
        );
    }

    public function testRejectsOperationThatLeavesTheThinkPhpTransactionOpen(): void
    {
        ThinkPhpTestConnection::fromPdo($this->connection());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('transaction remained open');

        (new WorkflowAtomicityContractHarness())->assertAtomic(
            static function (callable $checkpoint): void {
                Db::connect()->startTrans();
                $checkpoint('definition_written');
            },
            ['definition' => static fn(): int => 0],
            ['definition' => 0],
            ['definition_written'],
        );
    }

    private function connection(): PDO
    {
        return new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
