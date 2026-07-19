<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Operation;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Idempotency\IdempotencyKey;
use PeanutAdmin\Kernel\Idempotency\PdoIdempotencyRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoAuditRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoTransactionManager;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;
use RuntimeException;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class OperationAtomicityPrimitivesTest extends DatabaseTestCase
{
    public function testFailureAtEveryCheckpointLeavesNoPartialOperationState(): void
    {
        [$context, $tenantId, $memberId] = $this->fixture();
        foreach ($this->checkpoints() as $failurePoint) {
            try {
                $this->operation($context, $tenantId, $memberId, static function (string $checkpoint) use ($failurePoint): void {
                    if ($checkpoint === $failurePoint) {
                        throw new RuntimeException('injected:' . $checkpoint);
                    }
                });
                self::fail('The operation must fail at ' . $failurePoint);
            } catch (RuntimeException $exception) {
                self::assertSame('injected:' . $failurePoint, $exception->getMessage());
            }

            self::assertSame([0, 0, 0, 0], $this->state());
        }
    }

    public function testSuccessfulOperationCommitsDomainAuditOutboxAndIdempotencyTogether(): void
    {
        [$context, $tenantId, $memberId] = $this->fixture();

        $this->operation($context, $tenantId, $memberId, static fn(string $checkpoint): null => null);

        self::assertSame([1, 1, 1, 1], $this->state());
        self::assertSame(
            'completed',
            $this->query('SELECT status FROM pa_tenant_idempotency_record')->fetchColumn(),
        );
        self::assertSame(
            'success',
            $this->query('SELECT outcome FROM pa_tenant_audit_event')->fetchColumn(),
        );
        self::assertStringNotContainsString(
            'PEANUT',
            (string) $this->query('SELECT idempotency_key_hash FROM pa_tenant_idempotency_record')->fetchColumn(),
        );
    }

    public function testAuditOutcomesAreTypedAndRollbackWithTheirTransaction(): void
    {
        [$context] = $this->fixture();
        $transactions = new PdoTransactionManager($this->database);
        $audit = new PdoAuditRepository($this->database);

        $transactions->run(fn() => $audit->appendTenantMember(
            $context,
            'fixture.command.denied',
            'fixture.command.execute',
            metadata: ['reason_code' => 'FIXTURE_DENIED'],
            outcome: AuditOutcome::Denied,
        ));
        $transactions->run(fn() => $audit->appendTenantMember(
            $context,
            'fixture.command.error',
            'fixture.command.execute',
            metadata: ['error_class' => 'fixture'],
            outcome: AuditOutcome::Error,
        ));

        try {
            $transactions->run(function () use ($audit, $context): void {
                $audit->appendTenantMember(
                    $context,
                    'fixture.command.rolled-back',
                    'fixture.command.execute',
                    outcome: AuditOutcome::Success,
                );
                throw new RuntimeException('rollback audit');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(
            ['denied', 'error'],
            $this->query('SELECT outcome FROM pa_tenant_audit_event ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN),
        );
        self::assertSame(
            [null, null],
            $this->query('SELECT target_resource_id FROM pa_tenant_audit_event ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN),
        );
    }

    /**
     * @param callable(string): void $checkpoint
     */
    private function operation(
        TenantContext $context,
        int $tenantId,
        int $memberId,
        callable $checkpoint,
    ): void {
        $transactions = new PdoTransactionManager($this->database);
        $idempotency = new PdoIdempotencyRepository($this->database);
        $audit = new PdoAuditRepository($this->database);
        $now = new DateTimeImmutable('2026-07-19T00:00:00Z');

        $transactions->run(function () use ($context, $tenantId, $memberId, $checkpoint, $idempotency, $audit, $now): void {
            $record = $idempotency->beginTenant(
                $tenantId,
                $memberId,
                'fixture.command.execute',
                IdempotencyKey::fromString('01KPEANUTADMIN-ATOMIC-0001'),
                hash('sha256', 'fixture-request'),
                $now->modify('+1 hour'),
                $now,
            );
            self::assertTrue($record->acquiredForExecution());
            $checkpoint('idempotency_acquired');

            $this->database->exec("INSERT INTO fixture_atomic_domain (value) VALUES ('domain')");
            $checkpoint('domain_written');

            $audit->appendTenantMember(
                $context,
                'fixture.command.succeeded',
                'fixture.command.execute',
                'fixture.record',
                '1',
                metadata: ['secret_recorded' => false],
                outcome: AuditOutcome::Success,
            );
            $checkpoint('audit_written');

            $this->database->exec("INSERT INTO fixture_atomic_outbox (event_key) VALUES ('fixture.command.succeeded')");
            $checkpoint('outbox_written');

            $idempotency->completeTenant($record->id, 201, ['data' => ['id' => '1']], 'fixture.record', '1');
            $checkpoint('idempotency_completed');
        });
    }

    /** @return array{TenantContext, int, int} */
    private function fixture(): array
    {
        $this->runner->migrate();
        $this->database->exec(<<<'SQL'
CREATE TABLE fixture_atomic_domain (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    value VARCHAR(80) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE fixture_atomic_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(160) NOT NULL
) ENGINE=InnoDB
SQL);
        $now = '2026-07-19 00:00:00.000';
        $accountId = $this->insert('pa_account', [
            'display_name' => 'Atomic Fixture', 'status' => 'active', 'security_revision' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $tenantId = $this->insert('pa_tenant', [
            'code' => 'atomic', 'name' => 'Atomic', 'display_name' => 'Atomic', 'status' => 'active',
            'locale' => 'zh-CN', 'timezone' => 'Asia/Shanghai', 'security_revision' => 1,
            'authorization_revision' => 1, 'revision' => 1, 'activated_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $memberId = $this->insert('pa_tenant_member', [
            'tenant_id' => $tenantId, 'account_id' => $accountId, 'member_type' => 'internal',
            'status' => 'active', 'security_revision' => 1, 'authorization_revision' => 1,
            'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $session = new ValidatedTenantSession(
            1,
            'session_atomic_fixture',
            $tenantId,
            $accountId,
            $memberId,
            'atomic-web',
            new DateTimeImmutable($now, new DateTimeZone('UTC')),
            1,
        );

        return [TenantContext::fromValidatedSession($session, 'req_atomic_fixture'), $tenantId, $memberId];
    }

    /** @return list<string> */
    private function checkpoints(): array
    {
        return [
            'idempotency_acquired',
            'domain_written',
            'audit_written',
            'outbox_written',
            'idempotency_completed',
        ];
    }

    /** @return list<int> */
    private function state(): array
    {
        return [
            (int) $this->query('SELECT COUNT(*) FROM fixture_atomic_domain')->fetchColumn(),
            (int) $this->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn(),
            (int) $this->query('SELECT COUNT(*) FROM fixture_atomic_outbox')->fetchColumn(),
            (int) $this->query('SELECT COUNT(*) FROM pa_tenant_idempotency_record')->fetchColumn(),
        ];
    }
}
