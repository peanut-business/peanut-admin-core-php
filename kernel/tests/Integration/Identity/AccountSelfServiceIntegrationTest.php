<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Identity;

use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\SelfService\AccountSelfService;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class AccountSelfServiceIntegrationTest extends DatabaseTestCase
{
    private const NOW = '2026-07-19 01:00:00.000';
    private const CURRENT_PASSWORD = 'Current-password-123!';

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
}
