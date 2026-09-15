<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Workflow;

use LogicException;
use PDO;
use RuntimeException;
use think\db\PDOConnection;
use think\facade\Db;
use Throwable;

final class WorkflowAtomicityContractHarness
{
    public const CHECKPOINTS = [
        'definition_written',
        'instance_written',
        'work_item_written',
        'event_written',
        'audit_written',
        'notification_written',
        'task_written',
        'idempotency_completed',
    ];

    /**
     * @param callable(callable(string): void): mixed $operation
     * @param array<string, callable(): mixed> $stateProbes
     * @param array<string, mixed> $successfulState
     * @param non-empty-list<string> $checkpoints
     */
    public function assertAtomic(
        callable $operation,
        array $stateProbes,
        array $successfulState,
        array $checkpoints,
    ): void {
        $connection = $this->connection();
        $this->assertContract($stateProbes, $successfulState, $checkpoints);
        $this->assertConnectionIdle($connection, 'before workflow atomicity verification');
        foreach ($checkpoints as $injection) {
            $before = $this->snapshot($stateProbes);
            $failure = new RuntimeException("Injected workflow failure at {$injection}.");
            $reached = false;
            $propagated = false;
            $position = 0;
            try {
                $operation(function (string $checkpoint) use (
                    $connection,
                    $checkpoints,
                    $injection,
                    $failure,
                    &$reached,
                    &$position,
                ): void {
                    if (!$this->inTransaction($connection)) {
                        throw new LogicException("Workflow checkpoint was emitted outside the ThinkPHP transaction: {$checkpoint}.");
                    }
                    $expected = $checkpoints[$position] ?? null;
                    if (!is_string($expected) || !hash_equals($expected, $checkpoint)) {
                        throw new LogicException(sprintf(
                            'Unexpected workflow checkpoint "%s"; expected "%s".',
                            $checkpoint,
                            (string) $expected,
                        ));
                    }
                    ++$position;
                    if (hash_equals($checkpoint, $injection)) {
                        $reached = true;
                        throw $failure;
                    }
                });
            } catch (Throwable $actual) {
                if ($actual !== $failure) {
                    throw $actual;
                }
                $propagated = true;
            }
            $this->assertConnectionIdle($connection, "after injected failure at {$injection}");
            if (!$reached) {
                throw new LogicException("Workflow checkpoint was not reached: {$injection}.");
            }
            if (!$propagated) {
                throw new LogicException("Workflow operation swallowed injected failure at {$injection}.");
            }
            if ($before !== $this->snapshot($stateProbes)) {
                throw new LogicException("Workflow state changed after injected failure at {$injection}.");
            }
        }

        $position = 0;
        $operation(function (string $checkpoint) use ($connection, $checkpoints, &$position): void {
            if (!$this->inTransaction($connection)) {
                throw new LogicException("Workflow checkpoint was emitted outside the ThinkPHP transaction: {$checkpoint}.");
            }
            $expected = $checkpoints[$position] ?? null;
            if (!is_string($expected) || !hash_equals($expected, $checkpoint)) {
                throw new LogicException(sprintf(
                    'Unexpected workflow checkpoint "%s"; expected "%s".',
                    $checkpoint,
                    (string) $expected,
                ));
            }
            ++$position;
        });
        $this->assertConnectionIdle($connection, 'after successful workflow operation');
        if ($position !== count($checkpoints)) {
            throw new LogicException('A successful workflow operation omitted a required checkpoint.');
        }
        if ($successfulState !== $this->snapshot($stateProbes)) {
            throw new LogicException('Successful workflow state does not match the committed contract.');
        }
    }

    private function assertConnectionIdle(PDOConnection $connection, string $phase): void
    {
        if ($this->inTransaction($connection)) {
            $connection->rollback();
            throw new LogicException("The ThinkPHP transaction remained open {$phase}.");
        }
    }

    private function connection(): PDOConnection
    {
        $connection = Db::connect();
        if (!$connection instanceof PDOConnection) {
            throw new LogicException('Workflow atomicity verification requires ThinkPHP PDO transaction support.');
        }

        return $connection;
    }

    private function inTransaction(PDOConnection $connection): bool
    {
        $pdo = $connection->getPdo();
        if ($pdo === false) {
            return false;
        }
        if (!$pdo instanceof PDO) {
            throw new LogicException('Workflow atomicity verification cannot inspect the framework transaction.');
        }

        return $pdo->inTransaction();
    }

    /** @param array<string, callable(): mixed> $stateProbes
     * @param array<string, mixed> $successfulState
     * @param list<string> $checkpoints
     */
    private function assertContract(array $stateProbes, array $successfulState, array $checkpoints): void
    {
        if ($stateProbes === [] || array_keys($stateProbes) !== array_keys($successfulState)) {
            throw new LogicException('Workflow state probes and successful state must have identical non-empty keys.');
        }
        if ($checkpoints === [] || count($checkpoints) !== count(array_unique($checkpoints, SORT_STRING))) {
            throw new LogicException('Workflow checkpoints must be a non-empty unique list.');
        }
        $last = -1;
        foreach ($checkpoints as $checkpoint) {
            $position = array_search($checkpoint, self::CHECKPOINTS, true);
            if (!is_int($position) || $position <= $last) {
                throw new LogicException('Workflow checkpoints are unknown or out of canonical order.');
            }
            $last = $position;
        }
    }

    /**
     * @param array<string, callable(): mixed> $stateProbes
     * @return array<string, mixed>
     */
    private function snapshot(array $stateProbes): array
    {
        $snapshot = [];
        foreach ($stateProbes as $name => $probe) {
            $snapshot[$name] = $probe();
        }

        return $snapshot;
    }
}
