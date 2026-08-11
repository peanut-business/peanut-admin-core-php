<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Tests\Unit\Workflow;

use LogicException;
use PDO;
use PeanutAdmin\Testing\Workflow\WorkflowAtomicityContractHarness;
use PHPUnit\Framework\TestCase;
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
        $operation = static function (PDO $pdo, callable $checkpoint) use (&$state): void {
            $before = $state;
            $pdo->beginTransaction();
            try {
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
                $pdo->commit();
            } catch (Throwable $exception) {
                $state = $before;
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
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
            $this->connection(),
            $operation,
            $probes,
            ['workflow' => 3, 'audit' => 1, 'notification' => 1, 'task' => 1, 'idempotency' => 1],
            $checkpoints,
        );
        self::addToAssertionCount(1);
    }

    public function testRejectsOutOfOrderCheckpointSelection(): void
    {
        $this->expectException(LogicException::class);
        (new WorkflowAtomicityContractHarness())->assertAtomic(
            $this->connection(),
            static fn(PDO $pdo, callable $checkpoint) => null,
            ['workflow' => static fn(): int => 0],
            ['workflow' => 0],
            ['audit_written', 'event_written'],
        );
    }

    public function testRejectsAnOperationThatSwallowsTheInjectedFailure(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('swallowed injected failure');

        (new WorkflowAtomicityContractHarness())->assertAtomic(
            $this->connection(),
            static function (PDO $pdo, callable $checkpoint): void {
                $pdo->beginTransaction();
                try {
                    $checkpoint('definition_written');
                } catch (Throwable) {
                    // Deliberately invalid fixture: rollback without propagation is not evidence.
                    $pdo->rollBack();
                }
            },
            ['definition' => static fn(): int => 0],
            ['definition' => 0],
            ['definition_written'],
        );
    }

    public function testRejectsCheckpointOutsideTheSuppliedPdoTransaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('outside the supplied PDO transaction');

        (new WorkflowAtomicityContractHarness())->assertAtomic(
            $this->connection(),
            static function (PDO $pdo, callable $checkpoint): void {
                $checkpoint('definition_written');
            },
            ['definition' => static fn(): int => 0],
            ['definition' => 0],
            ['definition_written'],
        );
    }

    public function testRejectsOperationThatLeavesTheSuppliedPdoTransactionOpen(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('transaction remained open');

        (new WorkflowAtomicityContractHarness())->assertAtomic(
            $this->connection(),
            static function (PDO $pdo, callable $checkpoint): void {
                $pdo->beginTransaction();
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
