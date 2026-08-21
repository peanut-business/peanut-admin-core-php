<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform\Bootstrap;

use DomainException;
use PeanutAdmin\Kernel\Audit\AuditRepository;
use PeanutAdmin\Kernel\Identity\AccountStatus;
use PeanutAdmin\Kernel\Identity\CredentialStatus;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\IdentityRepository;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Membership\MembershipRepository;
use PeanutAdmin\Kernel\Membership\TenantMemberStatus;
use PeanutAdmin\Kernel\Persistence\TransactionManager;
use PeanutAdmin\Kernel\Platform\PlatformRepository;
use PeanutAdmin\Kernel\Tenancy\TenantRepository;
use PeanutAdmin\Kernel\Tenancy\TenantStatus;

final readonly class BootstrapService
{
    private const PLATFORM_OWNER_ROLE = 'platform.bootstrap-owner';
    private const TENANT_OWNER_ROLE = 'core.tenant-owner';

    public function __construct(
        private TransactionManager $transactions,
        private IdentityRepository $identity,
        private TenantRepository $tenants,
        private MembershipRepository $memberships,
        private PlatformRepository $platform,
        private AuditRepository $audit,
        private PasswordHasher $passwords,
    ) {}

    public function bootstrapPlatformOwner(
        string $email,
        string $plainPassword,
        string $displayName,
        string $requestId,
    ): PlatformBootstrapResult {
        $normalizedEmail = EmailAddress::fromString($email);
        $this->platform->acquireBootstrapLock();

        try {
            return $this->transactions->run(function () use (
                $normalizedEmail,
                $plainPassword,
                $displayName,
                $requestId,
            ): PlatformBootstrapResult {
                if ($this->platform->operatorCount() !== 0) {
                    throw new DomainException('Platform bootstrap has already completed.');
                }

                $credential = $this->identity->credentialByEmail($normalizedEmail, true);
                if ($credential === null) {
                    $account = $this->identity->createAccount($displayName);
                    $credential = $this->identity->createEmailCredential(
                        $account->id,
                        $normalizedEmail,
                        $this->passwords->hash($plainPassword),
                    );
                } else {
                    if (!$this->passwords->verify($plainPassword, $credential->secretHash)) {
                        throw new DomainException('Existing credential cannot be overwritten by bootstrap.');
                    }
                    $account = $this->identity->accountById($credential->accountId, true);
                    if ($account === null || $account->status !== AccountStatus::Active) {
                        throw new DomainException('Existing bootstrap account is not active.');
                    }
                }

                if ($credential->status !== CredentialStatus::Active) {
                    throw new DomainException('Bootstrap credential is not active.');
                }

                $operator = $this->platform->createOperator($account->id, $displayName);
                $roleId = $this->platform->createBuiltinRole(
                    self::PLATFORM_OWNER_ROLE,
                    'Platform Bootstrap Owner',
                );
                $this->platform->assignRole($operator->id, $roleId);
                $this->audit->appendPlatform(
                    'platform.bootstrap.completed',
                    'platform.bootstrap',
                    $requestId,
                    $operator->id,
                    $account->id,
                );

                return new PlatformBootstrapResult($account->id, $operator->id, $roleId);
            });
        } finally {
            $this->platform->releaseBootstrapLock();
        }
    }

    public function provisionTenantOwnerCandidate(
        int $platformOperatorId,
        string $tenantCode,
        string $tenantName,
        string $ownerEmail,
        ?string $initialPassword,
        string $ownerDisplayName,
        string $requestId,
    ): TenantOwnerCandidateResult {
        $email = EmailAddress::fromString($ownerEmail);

        return $this->transactions->run(function () use (
            $platformOperatorId,
            $tenantCode,
            $tenantName,
            $email,
            $initialPassword,
            $ownerDisplayName,
            $requestId,
        ): TenantOwnerCandidateResult {
            $operator = $this->platform->operatorById($platformOperatorId, true);
            if ($operator === null || $operator->status->value !== 'active') {
                throw new DomainException('Active platform operator is required.');
            }

            $tenant = $this->tenants->createProvisioning($tenantCode, $tenantName);
            $this->tenants->byId($tenant->id, true);
            $role = $this->memberships->createBuiltinRole(
                $tenant->id,
                self::TENANT_OWNER_ROLE,
                'Tenant Owner',
            );

            $credential = $this->identity->credentialByEmail($email, true);
            if ($credential === null) {
                if ($initialPassword === null) {
                    throw new DomainException('Initial password is required for a new account.');
                }
                $account = $this->identity->createAccount($ownerDisplayName);
                $this->identity->createEmailCredential(
                    $account->id,
                    $email,
                    $this->passwords->hash($initialPassword),
                );
            } else {
                if ($initialPassword !== null) {
                    throw new DomainException('Password must not be supplied for an existing email.');
                }
                $account = $this->identity->accountById($credential->accountId, true);
                if ($account === null || $account->status !== AccountStatus::Active) {
                    throw new DomainException('Existing owner account is not active.');
                }
            }

            if ($this->memberships->pendingOrActiveMemberWithRoleExists(
                $tenant->id,
                self::TENANT_OWNER_ROLE,
            )) {
                throw new DomainException('Tenant owner candidate already exists.');
            }

            $member = $this->memberships->createPending(
                $tenant->id,
                $account->id,
                $ownerDisplayName,
            );
            $this->memberships->assignRole($tenant->id, $member->id, $role->id);
            $this->audit->appendPlatform(
                'tenant.owner-candidate.created',
                'platform.tenant.provision-owner',
                $requestId,
                $operator->id,
                $operator->accountId,
                ['tenant_id' => $tenant->id, 'member_id' => $member->id],
            );

            return new TenantOwnerCandidateResult(
                $tenant->id,
                $account->id,
                $member->id,
                $role->id,
            );
        });
    }

    public function activateTenantOwner(
        int $platformOperatorId,
        int $tenantId,
        int $memberId,
        string $requestId,
    ): void {
        $this->transactions->run(function () use (
            $platformOperatorId,
            $tenantId,
            $memberId,
            $requestId,
        ): void {
            $operator = $this->platform->operatorById($platformOperatorId, true);
            $tenant = $this->tenants->byId($tenantId, true);
            $member = $this->memberships->byId($tenantId, $memberId, true);
            if ($operator === null || $operator->status->value !== 'active') {
                throw new DomainException('Active platform operator is required.');
            }
            if ($tenant === null || $tenant->status !== TenantStatus::Provisioning) {
                throw new DomainException('Owner activation requires a provisioning tenant.');
            }
            if ($member === null || $member->status !== TenantMemberStatus::Pending) {
                throw new DomainException('Pending owner candidate was not found.');
            }
            if (!$this->memberships->memberHasRole($tenantId, $memberId, self::TENANT_OWNER_ROLE)) {
                throw new DomainException('Owner candidate does not hold the owner role.');
            }

            $account = $this->identity->accountById($member->accountId, true);
            $credential = $this->identity->activeCredentialForAccount($member->accountId, true);
            if ($account === null || $account->status !== AccountStatus::Active || $credential === null) {
                throw new DomainException('Owner account and credential must be active.');
            }

            $this->memberships->transition($tenantId, $memberId, TenantMemberStatus::Active);
            $this->audit->appendTenantPlatformOperator(
                $tenantId,
                $operator->id,
                $operator->accountId,
                'tenant.owner-candidate.activated',
                'platform.tenant.provision-owner',
                $requestId,
                ['member_id' => $memberId],
            );
        });
    }

    public function activateTenant(
        int $platformOperatorId,
        int $tenantId,
        string $requestId,
    ): void {
        $this->transactions->run(function () use ($platformOperatorId, $tenantId, $requestId): void {
            $operator = $this->platform->operatorById($platformOperatorId, true);
            $tenant = $this->tenants->byId($tenantId, true);
            if ($operator === null || $operator->status->value !== 'active') {
                throw new DomainException('Active platform operator is required.');
            }
            if ($tenant === null || $tenant->status !== TenantStatus::Provisioning) {
                throw new DomainException('Only a provisioning tenant can be activated.');
            }
            if (!$this->memberships->activeMemberWithRoleExists($tenantId, self::TENANT_OWNER_ROLE)) {
                throw new DomainException('Tenant requires an active owner before activation.');
            }

            $this->tenants->transition($tenantId, TenantStatus::Active);
            $this->audit->appendPlatform(
                'tenant.activated',
                'platform.tenant.lifecycle',
                $requestId,
                $operator->id,
                $operator->accountId,
                ['tenant_id' => $tenantId],
            );
        });
    }
}
