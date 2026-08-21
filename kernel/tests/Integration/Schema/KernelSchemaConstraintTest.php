<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Schema;

require_once __DIR__ . '/DatabaseTestCase.php';

final class KernelSchemaConstraintTest extends DatabaseTestCase
{
    private const NOW = '2026-07-16 01:00:00.000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
    }

    public function testIdentityAndTenantRelationshipsAreDatabaseEnforced(): void
    {
        $account = $this->account('Primary account');
        $otherAccount = $this->account('Other account');
        $alpha = $this->tenant('alpha');
        $beta = $this->tenant('beta');

        $this->credential($account, 'member@example.com');
        $this->assertDatabaseRejects(
            fn() => $this->credential($otherAccount, 'MEMBER@EXAMPLE.COM'),
        );

        $alphaDepartment = $this->department($alpha, 'operations');
        $betaDepartment = $this->department($beta, 'operations');
        $alphaMember = $this->member($alpha, $account, $alphaDepartment);
        $this->member($beta, $account, $betaDepartment);

        $this->assertDatabaseRejects(
            fn() => $this->department($alpha, 'operations'),
        );
        $this->assertDatabaseRejects(
            fn() => $this->department($alpha, 'cross-parent', $betaDepartment),
        );
        $this->assertDatabaseRejects(
            fn() => $this->member($alpha, $otherAccount, $betaDepartment),
        );

        $betaRole = $this->role($beta, 'manager');
        $this->assertDatabaseRejects(fn() => $this->insert('pa_member_role', [
            'tenant_id' => $alpha,
            'tenant_member_id' => $alphaMember,
            'role_id' => $betaRole,
            'assigned_at' => self::NOW,
        ]));

        $this->assertDatabaseRejects(fn() => $this->department(0, 'sentinel'));
        $this->assertDatabaseRejects(fn() => $this->insert('pa_department', [
            'tenant_id' => null,
            'code' => 'null-sentinel',
            'name' => 'Invalid',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]));
    }

    public function testAuditAndSessionPlanesPreserveTheirBoundaries(): void
    {
        $account = $this->account('Session account');
        $alpha = $this->tenant('alpha-session');
        $beta = $this->tenant('beta-session');
        $alphaMember = $this->member($alpha, $account);

        $platformColumns = $this
            ->query("SHOW COLUMNS FROM `pa_platform_audit_event`")
            ->fetchAll();
        $columnNames = array_column($platformColumns, 'Field');
        self::assertNotContains('tenant_id', $columnNames);

        $this->assertDatabaseRejects(fn() => $this->insert('pa_tenant_audit_event', [
            'tenant_id' => null,
            'event_type' => 'member.login',
            'action' => 'auth.login',
            'outcome' => 'success',
            'actor_type' => 'tenant_system',
            'request_id' => 'request-null',
            'occurred_at' => self::NOW,
        ]));
        $this->assertDatabaseRejects(fn() => $this->insert('pa_tenant_audit_event', [
            'tenant_id' => $alpha,
            'event_type' => 'member.login',
            'action' => 'auth.login',
            'outcome' => 'success',
            'actor_tenant_id' => $beta,
            'actor_type' => 'tenant_system',
            'request_id' => 'request-cross-tenant',
            'occurred_at' => self::NOW,
        ]));

        $this->assertDatabaseRejects(fn() => $this->insert('pa_tenant_session', [
            'session_key' => '01JZ0000000000000000000001',
            'tenant_id' => $beta,
            'account_id' => $account,
            'tenant_member_id' => $alphaMember,
            'client_key' => 'admin-web',
            'account_security_revision' => 1,
            'tenant_security_revision' => 1,
            'member_security_revision' => 1,
            'issued_at' => self::NOW,
            'last_seen_at' => self::NOW,
            'idle_expires_at' => '2026-07-16 09:00:00.000',
            'absolute_expires_at' => '2026-07-30 01:00:00.000',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]));
    }

    public function testTenantClientKeysAllowRegisteredShapesButRejectUnsafeValues(): void
    {
        $account = $this->account('Client account');
        $tenant = $this->tenant('client-tenant');
        $member = $this->member($tenant, $account);
        $base = [
            'tenant_id' => $tenant,
            'account_id' => $account,
            'tenant_member_id' => $member,
            'account_security_revision' => 1,
            'tenant_security_revision' => 1,
            'member_security_revision' => 1,
            'issued_at' => self::NOW,
            'last_seen_at' => self::NOW,
            'idle_expires_at' => '2026-07-16 09:00:00.000',
            'absolute_expires_at' => '2026-07-30 01:00:00.000',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ];

        $this->insert('pa_tenant_session', $base + [
            'session_key' => '01JZ0000000000000000000011',
            'client_key' => 'single-store-web',
        ]);
        $this->assertDatabaseRejects(fn() => $this->insert('pa_tenant_session', $base + [
            'session_key' => '01JZ0000000000000000000012',
            'client_key' => 'Single Store Web',
        ]));
    }

    private function account(string $displayName): int
    {
        return $this->insert('pa_account', [
            'display_name' => $displayName,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function credential(int $accountId, string $email): int
    {
        return $this->insert('pa_credential', [
            'account_id' => $accountId,
            'kind' => 'email_password',
            'identifier_type' => 'email',
            'identifier_normalized' => $email,
            'secret_hash' => '$argon2id$v=19$m=65536,t=4,p=1$test$test',
            'verified_at' => self::NOW,
            'secret_changed_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function tenant(string $code): int
    {
        return $this->insert('pa_tenant', [
            'code' => $code,
            'name' => ucfirst($code),
            'display_name' => ucfirst($code),
            'status' => 'active',
            'activated_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function department(int $tenantId, string $code, ?int $parentId = null): int
    {
        return $this->insert('pa_department', [
            'tenant_id' => $tenantId,
            'parent_id' => $parentId,
            'code' => $code,
            'name' => ucfirst($code),
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function member(int $tenantId, int $accountId, ?int $departmentId = null): int
    {
        return $this->insert('pa_tenant_member', [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'primary_department_id' => $departmentId,
            'status' => 'active',
            'joined_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function role(int $tenantId, string $key): int
    {
        return $this->insert('pa_role', [
            'tenant_id' => $tenantId,
            'key' => $key,
            'name' => ucfirst($key),
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }
}
