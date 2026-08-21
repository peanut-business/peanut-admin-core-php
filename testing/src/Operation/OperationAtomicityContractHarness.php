<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Operation;

use LogicException;
use Throwable;

final class OperationAtomicityContractHarness
{
    public const REQUIRED_CHECKPOINTS = [
        'idempotency_acquired',
        'domain_written',
        'audit_written',
        'outbox_written',
        'idempotency_completed',
    ];

    private const REQUIRED_STATE_PROBES = [
        'domain',
        'audit',
        'outbox',
        'idempotency',
    ];

    /**
     * @param callable(callable(string): void): mixed $operation
     * @param array{
     *     domain: callable(): mixed,
     *     audit: callable(): mixed,
     *     outbox: callable(): mixed,
     *     idempotency: callable(): mixed
     * } $stateProbes
     * @param array{domain: mixed, audit: mixed, outbox: mixed, idempotency: mixed} $successfulState
     */
    public function assertAtomic(callable $operation, array $stateProbes, array $successfulState): void
    {
        /** @var array<string, true> $reached */
        $reached = [];

        foreach (self::REQUIRED_CHECKPOINTS as $injectionCheckpoint) {
            $before = $this->snapshot($stateProbes);
            $injectedFailure = new InjectedOperationFailure($injectionCheckpoint);
            $injectionCheckpointReached = false;
            $checkpointIndex = 0;

            try {
                $operation(static function (string $checkpoint) use (
                    $injectionCheckpoint,
                    $injectedFailure,
                    &$injectionCheckpointReached,
                    &$reached,
                    &$checkpointIndex,
                ): void {
                    if (!in_array($checkpoint, self::REQUIRED_CHECKPOINTS, true)) {
                        throw new LogicException(sprintf('Unknown operation checkpoint: "%s".', $checkpoint));
                    }
                    $expected = self::REQUIRED_CHECKPOINTS[$checkpointIndex] ?? null;
                    if ($checkpoint !== $expected) {
                        throw new LogicException(sprintf(
                            'Unexpected operation checkpoint "%s"; expected "%s".',
                            $checkpoint,
                            (string) $expected,
                        ));
                    }

                    $reached[$checkpoint] = true;
                    ++$checkpointIndex;
                    if ($checkpoint === $injectionCheckpoint) {
                        $injectionCheckpointReached = true;

                        throw $injectedFailure;
                    }
                });
            } catch (Throwable $exception) {
                if ($exception !== $injectedFailure) {
                    throw $exception;
                }
            }

            if ($checkpointIndex > count(self::REQUIRED_CHECKPOINTS)) {
                throw new LogicException('Operation emitted more checkpoints than required.');
            }

            if (!$injectionCheckpointReached) {
                throw new LogicException(sprintf(
                    'Required operation checkpoint was not reached: "%s".',
                    $injectionCheckpoint,
                ));
            }

            $after = $this->snapshot($stateProbes);
            foreach (self::REQUIRED_STATE_PROBES as $probe) {
                if ($before[$probe] !== $after[$probe]) {
                    throw new LogicException(sprintf(
                        'State probe "%s" changed after injected failure at checkpoint "%s".',
                        $probe,
                        $injectionCheckpoint,
                    ));
                }
            }
        }

        $checkpointIndex = 0;
        $operation(static function (string $checkpoint) use (&$checkpointIndex): void {
            if (!in_array($checkpoint, self::REQUIRED_CHECKPOINTS, true)) {
                throw new LogicException(sprintf('Unknown operation checkpoint: "%s".', $checkpoint));
            }
            $expected = self::REQUIRED_CHECKPOINTS[$checkpointIndex] ?? null;
            if ($checkpoint !== $expected) {
                throw new LogicException(sprintf(
                    'Unexpected operation checkpoint "%s"; expected "%s".',
                    $checkpoint,
                    (string) $expected,
                ));
            }
            ++$checkpointIndex;
        });
        if ($checkpointIndex !== count(self::REQUIRED_CHECKPOINTS)) {
            throw new LogicException('A successful operation did not reach every required checkpoint.');
        }
        $after = $this->snapshot($stateProbes);
        foreach (self::REQUIRED_STATE_PROBES as $probe) {
            if (!array_key_exists($probe, $successfulState) || $successfulState[$probe] !== $after[$probe]) {
                throw new LogicException(sprintf(
                    'Successful state probe "%s" does not match the expected committed state.',
                    $probe,
                ));
            }
        }

        foreach (self::REQUIRED_CHECKPOINTS as $checkpoint) {
            if (!isset($reached[$checkpoint])) {
                throw new LogicException(sprintf(
                    'Required operation checkpoint was not reached: "%s".',
                    $checkpoint,
                ));
            }
        }
    }

    /**
     * @param array{
     *     domain: callable(): mixed,
     *     audit: callable(): mixed,
     *     outbox: callable(): mixed,
     *     idempotency: callable(): mixed
     * } $stateProbes
     * @return array{domain: mixed, audit: mixed, outbox: mixed, idempotency: mixed}
     */
    private function snapshot(array $stateProbes): array
    {
        return [
            'domain' => $stateProbes['domain'](),
            'audit' => $stateProbes['audit'](),
            'outbox' => $stateProbes['outbox'](),
            'idempotency' => $stateProbes['idempotency'](),
        ];
    }
}
