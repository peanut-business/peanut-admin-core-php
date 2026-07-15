<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Domain;

use DomainException;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Membership\TenantMemberStatus;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoAuditRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoIdentityRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoMembershipRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoPlatformRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoTenantRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoTransactionManager;
use PeanutAdmin\Kernel\Platform\Bootstrap\BootstrapService;
use PeanutAdmin\Kernel\Platform\PlatformOperatorStatus;
use PeanutAdmin\Kernel\Tenancy\TenantStatus;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class BootstrapServiceIntegrationTest extends DatabaseTestCase
{
    private BootstrapService $bootstrap;
    private PdoIdentityRepository $identity;
    private PdoTenantRepository $tenants;
    private PdoMembershipRepository $memberships;
    private PdoPlatformRepository $platform;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();

        $this->identity = new PdoIdentityRepository($this->database);
        $this->tenants = new PdoTenantRepository($this->database);
        $this->memberships = new PdoMembershipRepository($this->database);
        $this->platform = new PdoPlatformRepository($this->database);
        $this->bootstrap = new BootstrapService(
            new PdoTransactionManager($this->database),
            $this->identity,
            $this->tenants,
            $this->memberships,
            $this->platform,
            new PdoAuditRepository($this->database),
            new PasswordHasher(),
        );
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
        $credential = $this->identity->activeCredentialForAccount($result->accountId);
        self::assertNotNull($credential);
        self::assertTrue((new PasswordHasher())->verify(
            'correct horse battery staple',
            $credential->secretHash,
        ));
        self::assertStringNotContainsString(
            'correct horse battery staple',
            json_encode($result->toArray(), JSON_THROW_ON_ERROR),
        );
        self::assertSame(0, $this->countRows('pa_tenant_member'));
        self::assertSame(0, $this->countRows('pa_platform_role_permission'));
        $this->assertDomainRejects(fn() => $this->platform->transitionOperator(
            $result->operatorId,
            PlatformOperatorStatus::Suspended,
        ));

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
        $originalCredential = $this->identity->activeCredentialForAccount($platform->accountId);
        self::assertNotNull($originalCredential);

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
            TenantMemberStatus::Pending,
            $this->memberships->byId($alpha->tenantId, $alpha->memberId)?->status,
        );
        self::assertSame(
            $originalCredential->secretHash,
            $this->identity->activeCredentialForAccount($platform->accountId)?->secretHash,
        );

        $this->bootstrap->activateTenantOwner(
            $platform->operatorId,
            $alpha->tenantId,
            $alpha->memberId,
            'request-activate-owner',
        );
        self::assertSame(
            TenantMemberStatus::Active,
            $this->memberships->byId($alpha->tenantId, $alpha->memberId)?->status,
        );
        self::assertSame(
            TenantStatus::Provisioning,
            $this->tenants->byId($alpha->tenantId)?->status,
        );

        $this->bootstrap->activateTenant(
            $platform->operatorId,
            $alpha->tenantId,
            'request-activate-tenant',
        );
        self::assertSame(TenantStatus::Active, $this->tenants->byId($alpha->tenantId)->status);

        $this->assertDomainRejects(fn() => $this->bootstrap->provisionTenantOwnerCandidate(
            $platform->operatorId,
            'invalid-password-overwrite',
            'Invalid Tenant',
            'owner@example.com',
            'must not replace existing password',
            'Owner',
            'request-overwrite',
        ));
        self::assertNull($this->tenants->byCode('invalid-password-overwrite'));
        self::assertSame(
            $originalCredential->secretHash,
            $this->identity->activeCredentialForAccount($platform->accountId)?->secretHash,
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

        $credential = $this->identity->activeCredentialForAccount($candidate->accountId);
        self::assertNotNull($credential);
        self::assertTrue((new PasswordHasher())->verify(
            'tenant correct horse password',
            $credential->secretHash,
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
