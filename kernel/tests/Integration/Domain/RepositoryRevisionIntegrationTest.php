<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Domain;

use DomainException;
use PeanutAdmin\Kernel\Identity\AccountStatus;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoIdentityRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoTenantRepository;
use PeanutAdmin\Kernel\Tenancy\TenantStatus;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class RepositoryRevisionIntegrationTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
    }

    public function testLifecycleUpdatesRevisionAndRejectsIllegalTransitions(): void
    {
        $identity = new PdoIdentityRepository($this->database);
        $tenants = new PdoTenantRepository($this->database);

        $account = $identity->createAccount('Lifecycle account');
        $disabled = $identity->transitionAccount($account->id, AccountStatus::Disabled);
        self::assertSame(2, $disabled->securityRevision);

        try {
            $identity->transitionAccount($account->id, AccountStatus::Locked);
            self::fail('Disabled account must not transition directly to locked.');
        } catch (DomainException) {
            self::assertSame(
                AccountStatus::Disabled,
                $identity->accountById($account->id)?->status,
            );
            self::assertSame(2, $identity->accountById($account->id)->securityRevision);
        }

        $tenant = $tenants->createProvisioning('revision-tenant', 'Revision Tenant');
        $active = $tenants->transition($tenant->id, TenantStatus::Active);
        self::assertSame(2, $active->securityRevision);
        self::assertSame(2, $active->revision);
    }
}
