<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Tests\Unit\Operation;

use LogicException;
use PeanutAdmin\Testing\Operation\InjectedOperationFailure;
use PeanutAdmin\Testing\Operation\OperationAtomicityContractHarness;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class OperationAtomicityContractHarnessTest extends TestCase
{
    public function testInjectsEveryRequiredCheckpointAndAcceptsRestoredState(): void
    {
        $state = ['domain' => 0, 'audit' => 0, 'outbox' => 0, 'idempotency' => 0];
        $injectedAt = [];
        $operation = static function (callable $checkpoint) use (&$state, &$injectedAt): void {
            $before = $state;
            try {
                ++$state['idempotency'];
                $checkpoint('idempotency_acquired');
                ++$state['domain'];
                $checkpoint('domain_written');
                ++$state['audit'];
                $checkpoint('audit_written');
                ++$state['outbox'];
                $checkpoint('outbox_written');
                ++$state['idempotency'];
                $checkpoint('idempotency_completed');
            } catch (Throwable $exception) {
                $state = $before;
                if ($exception instanceof InjectedOperationFailure) {
                    $injectedAt[] = $exception->checkpoint;
                }

                throw $exception;
            }
        };

        $harness = new OperationAtomicityContractHarness();
        $harness->assertAtomic(
            $operation,
            $this->probes($state),
            ['domain' => 1, 'audit' => 1, 'outbox' => 1, 'idempotency' => 2],
        );

        $expectedCheckpoints = [
            'idempotency_acquired',
            'domain_written',
            'audit_written',
            'outbox_written',
            'idempotency_completed',
        ];
        self::assertSame($expectedCheckpoints, OperationAtomicityContractHarness::REQUIRED_CHECKPOINTS);
        self::assertSame($expectedCheckpoints, $injectedAt);
        self::assertSame(['domain' => 1, 'audit' => 1, 'outbox' => 1, 'idempotency' => 2], $state);
    }

    public function testRejectsStateThatDiffersAfterAnInjectedFailure(): void
    {
        $state = ['domain' => 0, 'audit' => 0, 'outbox' => 0, 'idempotency' => 0];
        $operation = static function (callable $checkpoint) use (&$state): void {
            ++$state['idempotency'];
            $checkpoint('idempotency_acquired');
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'State probe "idempotency" changed after injected failure at checkpoint "idempotency_acquired".',
        );

        (new OperationAtomicityContractHarness())->assertAtomic($operation, $this->probes($state), $state);
    }

    public function testRejectsUnknownCheckpoint(): void
    {
        $operation = static function (callable $checkpoint): void {
            $checkpoint('transaction_started');
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unknown operation checkpoint: "transaction_started".');

        (new OperationAtomicityContractHarness())->assertAtomic($operation, $this->constantProbes(), $this->zeroState());
    }

    public function testRejectsRequiredCheckpointThatIsNotReached(): void
    {
        $operation = static function (callable $checkpoint): void {
            $checkpoint('idempotency_acquired');
            $checkpoint('domain_written');
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Required operation checkpoint was not reached: "audit_written".');

        (new OperationAtomicityContractHarness())->assertAtomic($operation, $this->constantProbes(), $this->zeroState());
    }

    public function testRethrowsNonInjectedExceptionWithoutWrappingIt(): void
    {
        $expected = new RuntimeException('operation failed');
        $operation = static function (callable $checkpoint) use ($expected): void {
            throw $expected;
        };

        try {
            (new OperationAtomicityContractHarness())->assertAtomic($operation, $this->constantProbes(), $this->zeroState());
            self::fail('The operation exception must be rethrown.');
        } catch (RuntimeException $actual) {
            self::assertSame($expected, $actual);
        }
    }

    public function testRethrowsInjectedFailureThatWasNotCreatedByTheHarness(): void
    {
        $foreignFailure = new InjectedOperationFailure('idempotency_acquired');
        $operation = static function (callable $checkpoint) use ($foreignFailure): void {
            try {
                $checkpoint('idempotency_acquired');
            } catch (InjectedOperationFailure) {
                throw $foreignFailure;
            }
        };

        try {
            (new OperationAtomicityContractHarness())->assertAtomic($operation, $this->constantProbes(), $this->zeroState());
            self::fail('A foreign injected failure must be rethrown.');
        } catch (InjectedOperationFailure $actual) {
            self::assertSame($foreignFailure, $actual);
        }
    }

    public function testRejectsOutOfOrderOrDuplicateCheckpoint(): void
    {
        $operation = static function (callable $checkpoint): void {
            $checkpoint('idempotency_acquired');
            $checkpoint('idempotency_acquired');
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Unexpected operation checkpoint "idempotency_acquired"; expected "domain_written".',
        );

        (new OperationAtomicityContractHarness())->assertAtomic(
            $operation,
            $this->constantProbes(),
            $this->zeroState(),
        );
    }

    public function testRejectsSuccessfulStateThatWasNotCommitted(): void
    {
        $operation = static function (callable $checkpoint): void {
            foreach (OperationAtomicityContractHarness::REQUIRED_CHECKPOINTS as $name) {
                $checkpoint($name);
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Successful state probe "domain" does not match the expected committed state.',
        );

        (new OperationAtomicityContractHarness())->assertAtomic(
            $operation,
            $this->constantProbes(),
            ['domain' => 1, 'audit' => 1, 'outbox' => 1, 'idempotency' => 1],
        );
    }

    /**
     * @param array{domain: int, audit: int, outbox: int, idempotency: int} $state
     * @return array{
     *     domain: callable(): int,
     *     audit: callable(): int,
     *     outbox: callable(): int,
     *     idempotency: callable(): int
     * }
     */
    private function probes(array &$state): array
    {
        return [
            'domain' => static function () use (&$state): int {
                return $state['domain'];
            },
            'audit' => static function () use (&$state): int {
                return $state['audit'];
            },
            'outbox' => static function () use (&$state): int {
                return $state['outbox'];
            },
            'idempotency' => static function () use (&$state): int {
                return $state['idempotency'];
            },
        ];
    }

    /**
     * @return array{
     *     domain: callable(): int,
     *     audit: callable(): int,
     *     outbox: callable(): int,
     *     idempotency: callable(): int
     * }
     */
    private function constantProbes(): array
    {
        return [
            'domain' => static fn(): int => 0,
            'audit' => static fn(): int => 0,
            'outbox' => static fn(): int => 0,
            'idempotency' => static fn(): int => 0,
        ];
    }

    /** @return array{domain: int, audit: int, outbox: int, idempotency: int} */
    private function zeroState(): array
    {
        return ['domain' => 0, 'audit' => 0, 'outbox' => 0, 'idempotency' => 0];
    }
}
