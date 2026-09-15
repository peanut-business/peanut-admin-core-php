<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Domain;

use DomainException;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Membership\TenantMemberStatus;
use PeanutAdmin\Kernel\Platform\Bootstrap\BootstrapService;
use PeanutAdmin\Kernel\Tenancy\TenantStatus;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class BootstrapServiceIntegrationTest extends DatabaseTestCase
{
    private BootstrapService $bootstrap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();

        $this->bootstrap = new BootstrapService();
    }

    public function testPlatformBootstrapIsSecretSafeAndCannotBeRepeated(): void
    {
        $result = $this->bootstrap->bootstrapPlatformOwner(
            ' Owner@Example.com ',
            'correct horse battery staple',
            'Platform Owner',
            'request-platform-bootstrap',
        );

        self::assertSame(['account_id', 'operator_id', 'role_id'], array_keys($result->toArray()));
        $credentialHash = $this->activeCredentialHash($result->accountId);
        self::assertTrue((new PasswordHasher())->verify(
            'correct horse battery staple',
            $credentialHash,
        ));
        self::assertStringNotContainsString(
            'correct horse battery staple',
            json_encode($result->toArray(), JSON_THROW_ON_ERROR),
        );
        self::assertSame(0, $this->countRows('pa_tenant_member'));
        self::assertSame(0, $this->countRows('pa_platform_role_permission'));
        $this->assertDomainRejects(fn() => $this->bootstrap->bootstrapPlatformOwner(
            'second@example.com',
            'another correct horse battery staple',
            'Second Owner',
            'request-repeat',
        ));
        self::assertSame(1, $this->countRows('pa_platform_operator'));
        self::assertSame(1, $this->countRows('pa_credential'));
    }

    public function testTenantOwnerIsPendingThenExplicitlyActivatedWithoutRootDepartment(): void
    {
        $platform = $this->bootstrap->bootstrapPlatformOwner(
            'owner@example.com',
            'correct horse battery staple',
            'Owner',
            'request-platform',
        );
        $originalCredentialHash = $this->activeCredentialHash($platform->accountId);

        $alpha = $this->bootstrap->provisionTenantOwnerCandidate(
            $platform->operatorId,
            'alpha-company',
            'Alpha Company',
            'OWNER@example.com',
            null,
            'Alpha Owner',
            'request-alpha-owner',
        );
        $beta = $this->bootstrap->provisionTenantOwnerCandidate(
            $platform->operatorId,
            'beta-company',
            'Beta Company',
            'owner@example.com',
            null,
            'Beta Owner',
            'request-beta-owner',
        );

        self::assertSame($platform->accountId, $alpha->accountId);
        self::assertSame($alpha->accountId, $beta->accountId);
        self::assertSame(2, $this->countRows('pa_tenant_member'));
        self::assertSame(0, $this->countRows('pa_department'));
        self::assertSame(
            TenantMemberStatus::Pending->value,
            $this->memberStatus($alpha->tenantId, $alpha->memberId),
        );
        self::assertSame(
            $originalCredentialHash,
            $this->activeCredentialHash($platform->accountId),
        );

        $this->bootstrap->activateTenantOwner(
            $platform->operatorId,
            $alpha->tenantId,
            $alpha->memberId,
            'request-activate-owner',
        );
        self::assertSame(
            TenantMemberStatus::Active->value,
            $this->memberStatus($alpha->tenantId, $alpha->memberId),
        );
        self::assertSame(
            TenantStatus::Provisioning->value,
            $this->tenantStatus($alpha->tenantId),
        );

        $this->bootstrap->activateTenant(
            $platform->operatorId,
            $alpha->tenantId,
            'request-activate-tenant',
        );
        self::assertSame(TenantStatus::Active->value, $this->tenantStatus($alpha->tenantId));

        $this->assertDomainRejects(fn() => $this->bootstrap->provisionTenantOwnerCandidate(
            $platform->operatorId,
            'invalid-password-overwrite',
            'Invalid Tenant',
            'owner@example.com',
            'must not replace existing password',
            'Owner',
            'request-overwrite',
        ));
        self::assertSame(0, (int) $this->query(
            "SELECT COUNT(*) FROM pa_tenant WHERE code = 'invalid-password-overwrite'",
        )->fetchColumn());
        self::assertSame(
            $originalCredentialHash,
            $this->activeCredentialHash($platform->accountId),
        );
    }

    public function testNewTenantOwnerPasswordIsHashedAndAbsentFromResult(): void
    {
        $platform = $this->bootstrap->bootstrapPlatformOwner(
            'platform@example.com',
            'platform correct horse password',
            'Platform',
            'request-platform-new-owner',
        );
        $candidate = $this->bootstrap->provisionTenantOwnerCandidate(
            $platform->operatorId,
            'new-owner-company',
            'New Owner Company',
            'new-owner@example.com',
            'tenant correct horse password',
            'New Owner',
            'request-new-owner',
        );

        $credentialHash = $this->activeCredentialHash($candidate->accountId);
        self::assertTrue((new PasswordHasher())->verify(
            'tenant correct horse password',
            $credentialHash,
        ));
        self::assertStringNotContainsString(
            'tenant correct horse password',
            json_encode($candidate->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }

    private function activeCredentialHash(int $accountId): string
    {
        return (string) $this->query(
            "SELECT secret_hash FROM pa_credential WHERE account_id = {$accountId} AND status = 'active' ORDER BY id LIMIT 1",
        )->fetchColumn();
    }

    private function memberStatus(int $tenantId, int $memberId): string
    {
        return (string) $this->query(
            "SELECT status FROM pa_tenant_member WHERE tenant_id = {$tenantId} AND id = {$memberId}",
        )->fetchColumn();
    }

    private function tenantStatus(int $tenantId): string
    {
        return (string) $this->query("SELECT status FROM pa_tenant WHERE id = {$tenantId}")->fetchColumn();
    }

    private function assertDomainRejects(callable $operation): void
    {
        try {
            $operation();
        } catch (DomainException) {
            self::addToAssertionCount(1);

            return;
        }

        self::fail('Expected the domain operation to be rejected.');
    }
}
