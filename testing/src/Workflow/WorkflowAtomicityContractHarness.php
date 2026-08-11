<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Workflow;

use LogicException;
use PDO;
use RuntimeException;
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
     * @param callable(PDO, callable(string): void): mixed $operation
     * @param array<string, callable(): mixed> $stateProbes
     * @param array<string, mixed> $successfulState
     * @param non-empty-list<string> $checkpoints
     */
    public function assertAtomic(
        PDO $pdo,
        callable $operation,
        array $stateProbes,
        array $successfulState,
        array $checkpoints,
    ): void {
        $this->assertContract($stateProbes, $successfulState, $checkpoints);
        $this->assertConnectionIdle($pdo, 'before workflow atomicity verification');
        foreach ($checkpoints as $injection) {
            $before = $this->snapshot($stateProbes);
            $failure = new RuntimeException("Injected workflow failure at {$injection}.");
            $reached = false;
            $propagated = false;
            $position = 0;
            try {
                $operation($pdo, function (string $checkpoint) use (
                    $pdo,
                    $checkpoints,
                    $injection,
                    $failure,
                    &$reached,
                    &$position,
                ): void {
                    if (!$pdo->inTransaction()) {
                        throw new LogicException("Workflow checkpoint was emitted outside the supplied PDO transaction: {$checkpoint}.");
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
            $this->assertConnectionIdle($pdo, "after injected failure at {$injection}");
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
        $operation($pdo, static function (string $checkpoint) use ($pdo, $checkpoints, &$position): void {
            if (!$pdo->inTransaction()) {
                throw new LogicException("Workflow checkpoint was emitted outside the supplied PDO transaction: {$checkpoint}.");
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
        $this->assertConnectionIdle($pdo, 'after successful workflow operation');
        if ($position !== count($checkpoints)) {
            throw new LogicException('A successful workflow operation omitted a required checkpoint.');
        }
        if ($successfulState !== $this->snapshot($stateProbes)) {
            throw new LogicException('Successful workflow state does not match the committed contract.');
        }
    }

    private function assertConnectionIdle(PDO $pdo, string $phase): void
    {
        if ($pdo->inTransaction()) {
            throw new LogicException("The supplied PDO transaction remained open {$phase}.");
        }
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
