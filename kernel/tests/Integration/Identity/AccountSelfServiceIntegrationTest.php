<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Identity;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\SelfService\AccountSelfService;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class AccountSelfServiceIntegrationTest extends DatabaseTestCase
{
    private const NOW = '2026-07-19 01:00:00.000';
    private const CURRENT_PASSWORD = 'Current-password-123!';
    private const CONCURRENCY_TIMEOUT_SECONDS = 8.0;

    private AccountSelfService $service;
    private PasswordHasher $passwords;
    private int $accountId;
    private int $tenantId;
    private int $memberId;
    private int $credentialId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();

        $this->passwords = new PasswordHasher();
        $this->accountId = $this->insert('pa_account', [
            'display_name' => 'Original name',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->credentialId = $this->insert('pa_credential', [
            'account_id' => $this->accountId,
            'kind' => 'email_password',
            'identifier_type' => 'email',
            'identifier_normalized' => 'owner@example.test',
            'secret_hash' => $this->passwords->hash(self::CURRENT_PASSWORD),
            'verified_at' => self::NOW,
            'secret_changed_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->tenantId = $this->insert('pa_tenant', [
            'code' => 'self-service',
            'name' => 'Self service tenant',
            'display_name' => 'Self service tenant',
            'status' => 'active',
            'activated_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->memberId = $this->insert('pa_tenant_member', [
            'tenant_id' => $this->tenantId,
            'account_id' => $this->accountId,
            'display_name' => 'Tenant member',
            'status' => 'active',
            'joined_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->service = new AccountSelfService($this->database, $this->passwords);
    }

    public function testProfileIsSelfScopedMaskedAndAuditedOnUpdate(): void
    {
        $profile = $this->service->profile($this->tenantId, $this->memberId, $this->accountId);

        self::assertSame((string) $this->accountId, $profile['account_id']);
        self::assertSame('Original name', $profile['display_name']);
        self::assertSame('o***@example.test', $profile['credential']['identifier_masked']);
        self::assertArrayNotHasKey('secret_hash', $profile['credential']);

        try {
            $this->service->profile($this->tenantId + 1, $this->memberId, $this->accountId);
            self::fail('Expected a mismatched tenant/member/account binding to fail closed.');
        } catch (AdminAccessException $exception) {
            self::assertSame('ACCOUNT_CREDENTIAL_UNAVAILABLE', $exception->errorCode);
        }

        $updated = $this->service->updateProfile(
            $this->tenantId,
            $this->memberId,
            $this->accountId,
            'Updated name',
            'https://cdn.example.test/avatar.png',
            'request-profile-update',
        );

        self::assertSame('Updated name', $updated['display_name']);
        self::assertSame('https://cdn.example.test/avatar.png', $updated['avatar_uri']);
        self::assertSame('account.profile.changed', (string) $this->query(
            'SELECT action FROM pa_tenant_audit_event ORDER BY id DESC LIMIT 1',
        )->fetchColumn());
        self::assertStringNotContainsString('Updated name', (string) $this->query(
            'SELECT COALESCE(metadata_json, "") FROM pa_tenant_audit_event ORDER BY id DESC LIMIT 1',
        )->fetchColumn());
    }

    public function testWrongCurrentPasswordChangesNothingAndRecordsDeniedEvent(): void
    {
        try {
            $this->service->changePassword(
                $this->tenantId,
                $this->memberId,
                $this->accountId,
                'session-tenant-wrong',
                'wrong-current-password',
                'Replacement-password-456!',
                '127.0.0.1',
                'integration-test',
                'request-password-denied',
            );
            self::fail('Expected current password verification to fail.');
        } catch (AdminAccessException $exception) {
            self::assertSame('CURRENT_PASSWORD_INVALID', $exception->errorCode);
        }

        $hash = (string) $this->query(
            "SELECT secret_hash FROM pa_credential WHERE id = {$this->credentialId}",
        )->fetchColumn();
        self::assertTrue($this->passwords->verify(self::CURRENT_PASSWORD, $hash));
        self::assertSame('password_change_denied', (string) $this->query(
            'SELECT event_type FROM pa_auth_security_event ORDER BY id DESC LIMIT 1',
        )->fetchColumn());
        self::assertSame('denied', (string) $this->query(
            'SELECT outcome FROM pa_auth_security_event ORDER BY id DESC LIMIT 1',
        )->fetchColumn());
    }

    public function testEqualWrongPasswordsAreDeniedAndCountedBeforeUnchangedCheck(): void
    {
        $exception = $this->capturePasswordChangeError(
            $this->service,
            $this->tenantId,
            $this->memberId,
            $this->accountId,
            'wrong-current-password',
            'wrong-current-password',
            '192.0.2.20',
            'request-password-equal-wrong',
        );

        self::assertSame('CURRENT_PASSWORD_INVALID', $exception->errorCode);
        self::assertSame(1, $this->deniedCountForAccount($this->accountId));
        self::assertSame(1, $this->deniedCountForIp('192.0.2.20'));
        self::assertSame(1, (int) $this->query(
            "SELECT revision FROM pa_credential WHERE id = {$this->credentialId}",
        )->fetchColumn());
        self::assertSame(1, (int) $this->query(
            "SELECT security_revision FROM pa_account WHERE id = {$this->accountId}",
        )->fetchColumn());
    }

    public function testEqualValidPasswordsReturnUnchangedWithoutChangingState(): void
    {
        $exception = $this->capturePasswordChangeError(
            $this->service,
            $this->tenantId,
            $this->memberId,
            $this->accountId,
            self::CURRENT_PASSWORD,
            self::CURRENT_PASSWORD,
            '192.0.2.21',
            'request-password-equal-valid',
        );

        self::assertSame('PASSWORD_UNCHANGED', $exception->errorCode);
        self::assertSame(0, (int) $this->query(
            'SELECT COUNT(*) FROM pa_auth_security_event',
        )->fetchColumn());
        self::assertSame(0, (int) $this->query(
            'SELECT COUNT(*) FROM pa_tenant_audit_event',
        )->fetchColumn());
        self::assertSame(1, (int) $this->query(
            "SELECT revision FROM pa_credential WHERE id = {$this->credentialId}",
        )->fetchColumn());
        self::assertSame(1, (int) $this->query(
            "SELECT security_revision FROM pa_account WHERE id = {$this->accountId}",
        )->fetchColumn());
    }

    public function testProfileAndPasswordWritesRejectMismatchedTenantContext(): void
    {
        try {
            $this->service->updateProfile(
                $this->tenantId + 1,
                $this->memberId,
                $this->accountId,
                'Cross tenant update',
                null,
                'request-cross-tenant-profile',
            );
            self::fail('Expected cross-tenant profile update to fail closed.');
        } catch (AdminAccessException $exception) {
            self::assertSame('ACCOUNT_CREDENTIAL_UNAVAILABLE', $exception->errorCode);
        }

        try {
            $this->service->changePassword(
                $this->tenantId + 1,
                $this->memberId,
                $this->accountId,
                'session-cross-tenant-001',
                self::CURRENT_PASSWORD,
                'Replacement-password-456!',
                '127.0.0.1',
                'integration-test',
                'request-cross-tenant-password',
            );
            self::fail('Expected cross-tenant password change to fail closed.');
        } catch (AdminAccessException $exception) {
            self::assertSame('ACCOUNT_CREDENTIAL_UNAVAILABLE', $exception->errorCode);
        }

        self::assertSame(0, (int) $this->query(
            'SELECT COUNT(*) FROM pa_auth_security_event',
        )->fetchColumn());
        self::assertSame(0, (int) $this->query(
            'SELECT COUNT(*) FROM pa_tenant_audit_event',
        )->fetchColumn());
    }

    public function testPasswordChangeRevokesAllAccountSessionsAndTokens(): void
    {
        $tenantSession = $this->tenantSession();
        $platformSession = $this->platformSession();
        $tenantToken = $this->sessionToken('pa_tenant_session_token', $tenantSession);
        $platformToken = $this->sessionToken('pa_platform_session_token', $platformSession);
        $challenge = $this->insert('pa_login_challenge', [
            'challenge_key' => str_repeat('c', 26),
            'token_hash' => hash('sha256', 'active-password-change-challenge'),
            'account_id' => $this->accountId,
            'purpose' => 'tenant_switch',
            'client_key' => 'admin-web',
            'source_session_key' => 'tenant-session-key-000001',
            'ip_address' => '127.0.0.1',
            'expires_at' => '2026-07-20 01:00:00.000',
            'created_at' => self::NOW,
        ]);

        $this->service->changePassword(
            $this->tenantId,
            $this->memberId,
            $this->accountId,
            'tenant-session-key-000001',
            self::CURRENT_PASSWORD,
            'Replacement-password-456!',
            '127.0.0.1',
            'integration-test',
            'request-password-change',
        );

        $hash = (string) $this->query(
            "SELECT secret_hash FROM pa_credential WHERE id = {$this->credentialId}",
        )->fetchColumn();
        self::assertTrue($this->passwords->verify('Replacement-password-456!', $hash));
        self::assertFalse($this->passwords->verify(self::CURRENT_PASSWORD, $hash));
        self::assertSame(2, (int) $this->query(
            "SELECT security_revision FROM pa_account WHERE id = {$this->accountId}",
        )->fetchColumn());
        self::assertSame('revoked', $this->rowStatus('pa_tenant_session', $tenantSession));
        self::assertSame('revoked', $this->rowStatus('pa_platform_session', $platformSession));
        self::assertSame('revoked', $this->rowStatus('pa_tenant_session_token', $tenantToken));
        self::assertSame('revoked', $this->rowStatus('pa_platform_session_token', $platformToken));
        self::assertSame('revoked', $this->rowStatus('pa_login_challenge', $challenge));
        self::assertSame('password_changed', (string) $this->query(
            'SELECT event_type FROM pa_auth_security_event ORDER BY id DESC LIMIT 1',
        )->fetchColumn());
        self::assertSame('account.password.changed', (string) $this->query(
            'SELECT action FROM pa_tenant_audit_event ORDER BY id DESC LIMIT 1',
        )->fetchColumn());
    }

    public function testRepeatedWrongCurrentPasswordIsRateLimited(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $this->service->changePassword(
                    $this->tenantId,
                    $this->memberId,
                    $this->accountId,
                    'session-rate-limit-00001',
                    'wrong-current-password',
                    'Replacement-password-456!',
                    '192.0.2.10',
                    'integration-test',
                    'request-password-denied-' . $attempt,
                );
                self::fail('Expected current password verification to fail.');
            } catch (AdminAccessException $exception) {
                self::assertSame('CURRENT_PASSWORD_INVALID', $exception->errorCode);
            }
        }

        try {
            $this->service->changePassword(
                $this->tenantId,
                $this->memberId,
                $this->accountId,
                'session-rate-limit-00001',
                'wrong-current-password',
                'Replacement-password-456!',
                '192.0.2.10',
                'integration-test',
                'request-password-rate-limited',
            );
            self::fail('Expected password change attempts to be rate limited.');
        } catch (AdminAccessException $exception) {
            self::assertSame('PASSWORD_CHANGE_RATE_LIMITED', $exception->errorCode);
            self::assertSame(429, $exception->httpStatus);
        }

        self::assertSame(5, (int) $this->query(<<<'SQL'
SELECT COUNT(*) FROM pa_auth_security_event
WHERE event_type = 'password_change_denied'
SQL)->fetchColumn());
        self::assertSame('password_change_rate_limited', (string) $this->query(
            'SELECT event_type FROM pa_auth_security_event ORDER BY id DESC LIMIT 1',
        )->fetchColumn());
        $hash = (string) $this->query(
            "SELECT secret_hash FROM pa_credential WHERE id = {$this->credentialId}",
        )->fetchColumn();
        self::assertTrue($this->passwords->verify(self::CURRENT_PASSWORD, $hash));
    }

    public function testAccountAndIpDeniedBucketsAreNotAddedTogether(): void
    {
        $other = $this->secondaryIdentity('bucket-seed');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->deniedEvent(
                $this->accountId,
                $this->credentialId,
                '198.51.100.' . $attempt,
                'request-account-bucket-' . $attempt,
            );
            $this->deniedEvent(
                $other['account_id'],
                $other['credential_id'],
                '192.0.2.30',
                'request-ip-bucket-' . $attempt,
            );
        }

        $exception = $this->capturePasswordChangeError(
            $this->service,
            $this->tenantId,
            $this->memberId,
            $this->accountId,
            'wrong-current-password',
            'Replacement-password-456!',
            '192.0.2.30',
            'request-independent-buckets',
        );

        self::assertSame('CURRENT_PASSWORD_INVALID', $exception->errorCode);
        self::assertSame(4, $this->deniedCountForAccount($this->accountId));
        self::assertSame(4, $this->deniedCountForIp('192.0.2.30'));
    }

    public function testAccountBucketRateLimitsAcrossDifferentSourceIps(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $exception = $this->capturePasswordChangeError(
                $this->service,
                $this->tenantId,
                $this->memberId,
                $this->accountId,
                'wrong-current-password',
                'Replacement-password-456!',
                '198.51.100.' . $attempt,
                'request-account-limit-' . $attempt,
            );
            self::assertSame('CURRENT_PASSWORD_INVALID', $exception->errorCode);
        }

        $exception = $this->capturePasswordChangeError(
            $this->service,
            $this->tenantId,
            $this->memberId,
            $this->accountId,
            'wrong-current-password',
            'Replacement-password-456!',
            '198.51.100.100',
            'request-account-rate-limited',
        );

        self::assertSame('PASSWORD_CHANGE_RATE_LIMITED', $exception->errorCode);
        self::assertSame(5, $this->deniedCountForAccount($this->accountId));
        self::assertSame(0, $this->deniedCountForIp('198.51.100.100'));
    }

    public function testSourceIpBucketRateLimitsAcrossAccounts(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $identity = $this->secondaryIdentity('ip-limit-' . $attempt);
            $exception = $this->capturePasswordChangeError(
                $this->service,
                $this->tenantId,
                $identity['member_id'],
                $identity['account_id'],
                'wrong-current-password',
                'Replacement-password-456!',
                '192.0.2.40',
                'request-ip-limit-' . $attempt,
            );
            self::assertSame('CURRENT_PASSWORD_INVALID', $exception->errorCode);
        }

        $exception = $this->capturePasswordChangeError(
            $this->service,
            $this->tenantId,
            $this->memberId,
            $this->accountId,
            'wrong-current-password',
            'Replacement-password-456!',
            '192.0.2.40',
            'request-ip-rate-limited',
        );

        self::assertSame('PASSWORD_CHANGE_RATE_LIMITED', $exception->errorCode);
        self::assertSame(5, $this->deniedCountForIp('192.0.2.40'));
        self::assertSame(0, $this->deniedCountForAccount($this->accountId));
    }

    public function testConcurrentCrossAccountAttemptsCannotBypassSourceIpBucket(): void
    {
        $seed = $this->secondaryIdentity('concurrent-seed');
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->deniedEvent(
                $seed['account_id'],
                $seed['credential_id'],
                '192.0.2.50',
                'request-concurrent-seed-' . $attempt,
            );
        }
        $other = $this->secondaryIdentity('concurrent-other');
        $suffix = (string) getmypid() . '_' . bin2hex(random_bytes(4));
        $gateLock = 'pa_pwd_gate_' . $suffix;
        $firstArrivedLock = 'pa_pwd_arrived_1_' . $suffix;
        $secondArrivedLock = 'pa_pwd_arrived_2_' . $suffix;
        $sourceIpLock = $this->passwordChangeIpLockName('192.0.2.50');
        $firstRequestId = 'request-concurrent-first';
        $secondRequestId = 'request-concurrent-second';
        $gateConnection = null;
        $gateHeld = false;
        $triggerCreated = false;
        $processes = [];
        $sockets = [];
        $statuses = [];
        $outcomes = [];
        $firstArrived = false;
        $secondState = 'not_started';

        try {
            $first = $this->startPasswordChangeProcess(
                $this->memberId,
                $this->accountId,
                $firstRequestId,
            );
            $processes['first'] = $first['process_id'];
            $sockets['first'] = $first['socket'];
            $firstReady = $this->readSocketMessage($first['socket']);

            $second = $this->startPasswordChangeProcess(
                $other['member_id'],
                $other['account_id'],
                $secondRequestId,
            );
            $processes['second'] = $second['process_id'];
            $sockets['second'] = $second['socket'];
            $secondReady = $this->readSocketMessage($second['socket']);

            $gateConnection = $this->newConnection(self::DATABASE);
            $gateHeld = $this->acquireNamedLock($gateConnection, $gateLock) === 1;
            if (!$gateHeld) {
                throw new RuntimeException('Could not acquire the password-change test gate.');
            }
            $this->createPasswordDeniedGateTrigger(
                $gateConnection,
                $gateLock,
                $firstArrivedLock,
                $secondArrivedLock,
                $firstRequestId,
                $secondRequestId,
            );
            $triggerCreated = true;

            $this->writeSocketMessage($first['socket'], ['command' => 'start']);
            $firstArrived = $this->waitForNamedLockOwner($gateConnection, $firstArrivedLock);
            $this->writeSocketMessage($second['socket'], ['command' => 'start']);
            $secondState = $this->waitForSecondPasswordAttemptState(
                $gateConnection,
                $sourceIpLock,
                (int) ($secondReady['connection_id'] ?? 0),
                $secondArrivedLock,
            );

            if ($this->releaseNamedLock($gateConnection, $gateLock) !== 1) {
                throw new RuntimeException('Could not release the password-change test gate.');
            }
            $gateHeld = false;
            $outcomes = [
                (string) ($this->readSocketMessage($first['socket'])['outcome'] ?? ''),
                (string) ($this->readSocketMessage($second['socket'])['outcome'] ?? ''),
            ];
        } finally {
            if ($gateHeld && $gateConnection instanceof PDO) {
                try {
                    $this->releaseNamedLock($gateConnection, $gateLock);
                } catch (Throwable) {
                    // Dropping the dedicated connection below also releases its named locks.
                }
            }
            foreach ($sockets as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
            foreach ($processes as $name => $processId) {
                $statuses[$name] = $this->terminateAndReapProcess($processId);
            }
            if ($triggerCreated && $gateConnection instanceof PDO) {
                $gateConnection->exec('DROP TRIGGER IF EXISTS test_pause_password_change_denied');
            }
            $gateConnection = null;
            $this->admin = $this->newConnection();
            $this->database = $this->newConnection(self::DATABASE);
            $this->service = new AccountSelfService($this->database, $this->passwords);
        }

        self::assertSame('ready', $firstReady['type'] ?? null);
        self::assertSame('ready', $secondReady['type'] ?? null);
        self::assertTrue($firstArrived);
        self::assertSame(
            'waiting_for_source_ip_lock',
            $secondState,
            'The second request crossed the old precheck and reached the denied-event trigger.',
        );
        sort($outcomes);
        self::assertSame(['CURRENT_PASSWORD_INVALID', 'PASSWORD_CHANGE_RATE_LIMITED'], $outcomes);
        $this->assertProcessExitedSuccessfully($statuses['first'] ?? null);
        $this->assertProcessExitedSuccessfully($statuses['second'] ?? null);
        self::assertSame(5, $this->deniedCountForIp('192.0.2.50'));
        self::assertSame(1, (int) $this->query(<<<'SQL'
SELECT COUNT(*) FROM pa_auth_security_event
WHERE event_type = 'password_change_rate_limited' AND ip_address = '192.0.2.50'
SQL)->fetchColumn());
    }

    private function tenantSession(): int
    {
        return $this->insert('pa_tenant_session', [
            'session_key' => 'tenant-session-key-000001',
            'tenant_id' => $this->tenantId,
            'account_id' => $this->accountId,
            'tenant_member_id' => $this->memberId,
            'client_key' => 'admin-web',
            'account_security_revision' => 1,
            'tenant_security_revision' => 1,
            'member_security_revision' => 1,
            'issued_at' => self::NOW,
            'last_seen_at' => self::NOW,
            'idle_expires_at' => '2026-07-19 02:00:00.000',
            'absolute_expires_at' => '2026-07-20 01:00:00.000',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function platformSession(): int
    {
        $operatorId = $this->insert('pa_platform_operator', [
            'account_id' => $this->accountId,
            'display_name' => 'Platform operator',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return $this->insert('pa_platform_session', [
            'session_key' => 'platform-session-key-0001',
            'account_id' => $this->accountId,
            'platform_operator_id' => $operatorId,
            'client_key' => 'platform-web',
            'account_security_revision' => 1,
            'operator_security_revision' => 1,
            'issued_at' => self::NOW,
            'last_seen_at' => self::NOW,
            'idle_expires_at' => '2026-07-19 02:00:00.000',
            'absolute_expires_at' => '2026-07-20 01:00:00.000',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function sessionToken(string $table, int $sessionId): int
    {
        return $this->insert($table, [
            'session_id' => $sessionId,
            'token_type' => 'access',
            'token_hash' => hash('sha256', $table . ':' . $sessionId),
            'expires_at' => '2026-07-20 01:00:00.000',
            'created_at' => self::NOW,
        ]);
    }

    private function rowStatus(string $table, int $id): string
    {
        return (string) $this->query("SELECT status FROM `{$table}` WHERE id = {$id}")->fetchColumn();
    }

    /** @return array{account_id: int, credential_id: int, member_id: int} */
    private function secondaryIdentity(string $suffix): array
    {
        $accountId = $this->insert('pa_account', [
            'display_name' => 'Secondary ' . $suffix,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $secretHash = (string) $this->query(
            "SELECT secret_hash FROM pa_credential WHERE id = {$this->credentialId}",
        )->fetchColumn();
        $credentialId = $this->insert('pa_credential', [
            'account_id' => $accountId,
            'kind' => 'email_password',
            'identifier_type' => 'email',
            'identifier_normalized' => $suffix . '@example.test',
            'secret_hash' => $secretHash,
            'verified_at' => self::NOW,
            'secret_changed_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $memberId = $this->insert('pa_tenant_member', [
            'tenant_id' => $this->tenantId,
            'account_id' => $accountId,
            'display_name' => 'Secondary member ' . $suffix,
            'status' => 'active',
            'joined_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return [
            'account_id' => $accountId,
            'credential_id' => $credentialId,
            'member_id' => $memberId,
        ];
    }

    private function deniedEvent(int $accountId, int $credentialId, string $ipAddress, string $requestId): void
    {
        $this->insert('pa_auth_security_event', [
            'audience' => 'tenant',
            'event_type' => 'password_change_denied',
            'outcome' => 'denied',
            'reason_code' => 'current_password_invalid',
            'account_id' => $accountId,
            'credential_id' => $credentialId,
            'session_key' => 'session-rate-seed-00001',
            'request_id' => $requestId,
            'ip_address' => $ipAddress,
            'occurred_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s.v'),
        ]);
    }

    private function deniedCountForAccount(int $accountId): int
    {
        return (int) $this->query(<<<SQL
SELECT COUNT(*) FROM pa_auth_security_event
WHERE event_type = 'password_change_denied' AND account_id = {$accountId}
SQL)->fetchColumn();
    }

    private function deniedCountForIp(string $ipAddress): int
    {
        $statement = $this->database->prepare(<<<'SQL'
SELECT COUNT(*) FROM pa_auth_security_event
WHERE event_type = 'password_change_denied' AND ip_address = :ip_address
SQL);
        $statement->execute(['ip_address' => $ipAddress]);

        return (int) $statement->fetchColumn();
    }

    private function capturePasswordChangeError(
        AccountSelfService $service,
        int $tenantId,
        int $memberId,
        int $accountId,
        string $currentPassword,
        string $newPassword,
        string $ipAddress,
        string $requestId,
    ): AdminAccessException {
        try {
            $service->changePassword(
                $tenantId,
                $memberId,
                $accountId,
                'session-password-test-001',
                $currentPassword,
                $newPassword,
                $ipAddress,
                'integration-test',
                $requestId,
            );
        } catch (AdminAccessException $exception) {
            self::addToAssertionCount(1);

            return $exception;
        }

        self::fail('Expected password change to fail.');
    }

    private function newConnection(?string $database = null): PDO
    {
        return new PDO(
            'mysql:host=127.0.0.1;port=' . (getenv('MYSQL_PORT') ?: '3306')
            . ($database === null ? '' : ";dbname={$database}")
            . ';charset=utf8mb4',
            'root',
            getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function passwordChangeIpLockName(string $ipAddress): string
    {
        return 'pa-pwd:' . substr(hash('sha256', $ipAddress), 0, 57);
    }

    private function createPasswordDeniedGateTrigger(
        PDO $pdo,
        string $gateLock,
        string $firstArrivedLock,
        string $secondArrivedLock,
        string $firstRequestId,
        string $secondRequestId,
    ): void {
        $pdo->exec(<<<SQL
CREATE TRIGGER test_pause_password_change_denied
BEFORE INSERT ON pa_auth_security_event
FOR EACH ROW
BEGIN
    IF NEW.event_type = 'password_change_denied' AND NEW.request_id = '{$firstRequestId}' THEN
        SET @password_first_arrived = GET_LOCK('{$firstArrivedLock}', 0);
        SET @password_first_gate = GET_LOCK('{$gateLock}', 15);
        SET @password_first_gate_release = RELEASE_LOCK('{$gateLock}');
        SET @password_first_arrived_release = RELEASE_LOCK('{$firstArrivedLock}');
    END IF;
    IF NEW.event_type = 'password_change_denied' AND NEW.request_id = '{$secondRequestId}' THEN
        SET @password_second_arrived = GET_LOCK('{$secondArrivedLock}', 0);
        SET @password_second_gate = GET_LOCK('{$gateLock}', 15);
        SET @password_second_gate_release = RELEASE_LOCK('{$gateLock}');
        SET @password_second_arrived_release = RELEASE_LOCK('{$secondArrivedLock}');
    END IF;
END
SQL);
    }

    /** @return array{process_id: int, socket: resource} */
    private function startPasswordChangeProcess(int $memberId, int $accountId, string $requestId): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!is_array($sockets)) {
            throw new RuntimeException('Could not create the password-change process socket.');
        }

        $processId = pcntl_fork();
        if ($processId < 0) {
            fclose($sockets[0]);
            fclose($sockets[1]);

            throw new RuntimeException('Could not fork the password-change test process.');
        }
        if ($processId === 0) {
            fclose($sockets[0]);
            $this->runPasswordChangeProcess($sockets[1], $memberId, $accountId, $requestId);
        }

        fclose($sockets[1]);

        return ['process_id' => $processId, 'socket' => $sockets[0]];
    }

    /** @param resource $socket */
    private function runPasswordChangeProcess($socket, int $memberId, int $accountId, string $requestId): never
    {
        try {
            $exitCode = $this->passwordChangeProcessExitCode($socket, $memberId, $accountId, $requestId);
        } finally {
            fclose($socket);
        }

        exit($exitCode);
    }

    /** @param resource $socket */
    private function passwordChangeProcessExitCode($socket, int $memberId, int $accountId, string $requestId): int
    {
        try {
            $pdo = $this->newConnection(self::DATABASE);
            $connectionIdQuery = $pdo->query('SELECT CONNECTION_ID()');
            if ($connectionIdQuery === false) {
                throw new RuntimeException('Could not read the password-change child connection ID.');
            }
            $connectionId = (int) $connectionIdQuery->fetchColumn();
            $this->writeSocketMessage($socket, [
                'type' => 'ready',
                'connection_id' => $connectionId,
            ]);
            $command = $this->readSocketMessage($socket);
            if (($command['command'] ?? null) !== 'start') {
                throw new RuntimeException('Password-change child received an invalid command.');
            }

            $service = new AccountSelfService($pdo, new PasswordHasher());
            try {
                $service->changePassword(
                    $this->tenantId,
                    $memberId,
                    $accountId,
                    'session-password-test-001',
                    'wrong-current-password',
                    'Replacement-password-456!',
                    '192.0.2.50',
                    'integration-test',
                    $requestId,
                );
                $outcome = 'success';
            } catch (AdminAccessException $exception) {
                $outcome = $exception->errorCode;
            }
            $this->writeSocketMessage($socket, ['type' => 'outcome', 'outcome' => $outcome]);

            return 0;
        } catch (Throwable $exception) {
            try {
                $this->writeSocketMessage($socket, [
                    'type' => 'failure',
                    'error' => $exception::class,
                ]);
            } catch (Throwable) {
            }

            return 1;
        }
    }

    /**
     * @param resource $socket
     * @param array<string, int|string> $message
     */
    private function writeSocketMessage($socket, array $message): void
    {
        $payload = json_encode($message, JSON_THROW_ON_ERROR) . "\n";
        $offset = 0;
        $deadline = microtime(true) + self::CONCURRENCY_TIMEOUT_SECONDS;
        stream_set_blocking($socket, false);
        while ($offset < strlen($payload)) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('Timed out writing a password-change process message.');
            }
            $write = [$socket];
            $read = null;
            $except = null;
            $selected = stream_select(
                $read,
                $write,
                $except,
                (int) $remaining,
                (int) (($remaining - floor($remaining)) * 1_000_000),
            );
            if ($selected !== 1) {
                continue;
            }
            $written = fwrite($socket, substr($payload, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write a password-change process message.');
            }
            $offset += $written;
        }
    }

    /**
     * @param resource $socket
     * @return array<string, mixed>
     */
    private function readSocketMessage($socket): array
    {
        $payload = '';
        $deadline = microtime(true) + self::CONCURRENCY_TIMEOUT_SECONDS;
        stream_set_blocking($socket, false);
        while (!str_contains($payload, "\n")) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('Timed out reading a password-change process message.');
            }
            $read = [$socket];
            $write = null;
            $except = null;
            $selected = stream_select(
                $read,
                $write,
                $except,
                (int) $remaining,
                (int) (($remaining - floor($remaining)) * 1_000_000),
            );
            if ($selected !== 1) {
                continue;
            }
            $chunk = fread($socket, 8_192);
            if ($chunk === false || ($chunk === '' && feof($socket))) {
                throw new RuntimeException('Password-change process closed its socket without a message.');
            }
            $payload .= $chunk;
        }

        $encodedMessage = strtok($payload, "\n");
        if ($encodedMessage === false) {
            throw new RuntimeException('Password-change process returned an empty message.');
        }
        $message = json_decode($encodedMessage, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($message)) {
            throw new RuntimeException('Password-change process returned an invalid message.');
        }

        return $message;
    }

    private function acquireNamedLock(PDO $pdo, string $lockName): int
    {
        $statement = $pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $statement->execute(['lock_name' => $lockName]);

        return (int) $statement->fetchColumn();
    }

    private function releaseNamedLock(PDO $pdo, string $lockName): int
    {
        $statement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $statement->execute(['lock_name' => $lockName]);

        return (int) $statement->fetchColumn();
    }

    private function waitForNamedLockOwner(PDO $pdo, string $lockName): bool
    {
        $statement = $pdo->prepare('SELECT IS_USED_LOCK(:lock_name)');
        $deadline = microtime(true) + self::CONCURRENCY_TIMEOUT_SECONDS;
        do {
            $statement->execute(['lock_name' => $lockName]);
            if ($statement->fetchColumn() !== null) {
                return true;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function waitForSecondPasswordAttemptState(
        PDO $pdo,
        string $sourceIpLock,
        int $connectionId,
        string $arrivedLock,
    ): string {
        $pending = $pdo->prepare(<<<'SQL'
SELECT COUNT(*)
FROM performance_schema.metadata_locks lock_state
JOIN performance_schema.threads thread_state
  ON thread_state.THREAD_ID = lock_state.OWNER_THREAD_ID
WHERE lock_state.OBJECT_TYPE = 'USER LEVEL LOCK'
  AND lock_state.OBJECT_NAME = :lock_name
  AND lock_state.LOCK_STATUS = 'PENDING'
  AND thread_state.PROCESSLIST_ID = :connection_id
SQL);
        $arrived = $pdo->prepare('SELECT IS_USED_LOCK(:lock_name)');
        $deadline = microtime(true) + self::CONCURRENCY_TIMEOUT_SECONDS;
        do {
            $pending->execute([
                'lock_name' => $sourceIpLock,
                'connection_id' => $connectionId,
            ]);
            if ((int) $pending->fetchColumn() === 1) {
                return 'waiting_for_source_ip_lock';
            }
            $arrived->execute(['lock_name' => $arrivedLock]);
            if ($arrived->fetchColumn() !== null) {
                return 'crossed_precheck';
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        return 'timed_out';
    }

    private function terminateAndReapProcess(int $processId): int|false|null
    {
        $status = $this->waitForProcess($processId, self::CONCURRENCY_TIMEOUT_SECONDS);
        if ($status !== null) {
            return $status;
        }

        posix_kill($processId, SIGTERM);
        $status = $this->waitForProcess($processId, 1.0);
        if ($status !== null) {
            return $status;
        }

        posix_kill($processId, SIGKILL);

        return $this->waitForProcess($processId, 1.0);
    }

    private function waitForProcess(int $processId, float $timeoutSeconds): int|false|null
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $result = pcntl_waitpid($processId, $status, WNOHANG);
            if ($result === $processId) {
                return $status;
            }
            if ($result === -1) {
                return false;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function assertProcessExitedSuccessfully(int|false|null $status): void
    {
        self::assertIsInt($status, 'Password-change child could not be reaped before the deadline.');
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
    }
}
