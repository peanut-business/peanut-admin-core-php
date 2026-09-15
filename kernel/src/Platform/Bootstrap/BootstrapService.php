<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform\Bootstrap;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Identity\AccountStatus;
use PeanutAdmin\Kernel\Identity\CredentialStatus;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Membership\TenantMemberStatus;
use PeanutAdmin\Kernel\Persistence\Model\Account;
use PeanutAdmin\Kernel\Persistence\Model\Credential;
use PeanutAdmin\Kernel\Persistence\Model\MemberRole;
use PeanutAdmin\Kernel\Persistence\Model\PlatformOperator;
use PeanutAdmin\Kernel\Persistence\Model\PlatformOperatorRole;
use PeanutAdmin\Kernel\Persistence\Model\PlatformRole;
use PeanutAdmin\Kernel\Persistence\Model\Role;
use PeanutAdmin\Kernel\Persistence\Model\Tenant;
use PeanutAdmin\Kernel\Persistence\Model\TenantMember;
use PeanutAdmin\Kernel\Platform\PlatformOperatorStatus;
use PeanutAdmin\Kernel\Tenancy\TenantStatus;
use think\db\PDOConnection;
use think\db\Raw;
use think\facade\Db;

/** Fresh-install bootstrap over the application-managed ThinkPHP connection. */
final readonly class BootstrapService
{
    private const PLATFORM_OWNER_ROLE = 'platform.bootstrap-owner';
    private const TENANT_OWNER_ROLE = 'core.tenant-owner';
    private const PLATFORM_BOOTSTRAP_LOCK = 'peanut-admin:bootstrap:platform-owner';

    public function __construct(
        private AuditService $audit = new AuditService(),
        private PasswordHasher $passwords = new PasswordHasher(),
    ) {}

    public function bootstrapPlatformOwner(
        string $email,
        string $plainPassword,
        string $displayName,
        string $requestId,
    ): PlatformBootstrapResult {
        $normalizedEmail = EmailAddress::fromString($email);
        $this->acquireBootstrapLock();

        try {
            return Db::transaction(function () use (
                $normalizedEmail,
                $plainPassword,
                $displayName,
                $requestId,
            ): PlatformBootstrapResult {
                if (PlatformOperator::count() !== 0) {
                    throw new DomainException('Platform bootstrap has already completed.');
                }

                $credential = Credential::where('identifier_type', 'email')
                    ->where('identifier_normalized', $normalizedEmail->value())
                    ->lock(true)
                    ->find();
                if (!$credential instanceof Credential) {
                    $account = new Account();
                    $now = $this->now();
                    $account->save(['display_name' => $displayName, 'created_at' => $now, 'updated_at' => $now]);
                    $accountId = (int) $account->getKey();
                    $credential = new Credential();
                    $credential->save([
                        'account_id' => $accountId,
                        'kind' => 'email_password',
                        'identifier_type' => 'email',
                        'identifier_normalized' => $normalizedEmail->value(),
                        'secret_hash' => $this->passwords->hash($plainPassword),
                        'status' => CredentialStatus::Active->value,
                        'verified_at' => $now,
                        'secret_changed_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    if (!$this->passwords->verify($plainPassword, (string) $credential->getAttr('secret_hash'))) {
                        throw new DomainException('Existing credential cannot be overwritten by bootstrap.');
                    }
                    $accountId = (int) $credential->getAttr('account_id');
                    $account = Account::where('id', $accountId)->lock(true)->find();
                    if (!$account instanceof Account
                        || AccountStatus::from((string) $account->getAttr('status')) !== AccountStatus::Active) {
                        throw new DomainException('Existing bootstrap account is not active.');
                    }
                }
                if (CredentialStatus::from((string) $credential->getAttr('status')) !== CredentialStatus::Active) {
                    throw new DomainException('Bootstrap credential is not active.');
                }

                $now = $this->now();
                $operator = new PlatformOperator();
                $operator->save([
                    'account_id' => $accountId,
                    'display_name' => $displayName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $operatorId = (int) $operator->getKey();
                $roleId = (int) PlatformRole::insertGetId([
                    'key' => self::PLATFORM_OWNER_ROLE,
                    'name' => 'Platform Bootstrap Owner',
                    'is_builtin' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                PlatformOperatorRole::insert([
                    'platform_operator_id' => $operatorId,
                    'platform_role_id' => $roleId,
                    'assigned_at' => $now,
                ]);
                PlatformOperator::where('id', $operatorId)->inc('security_revision')->update(['updated_at' => $now]);
                $this->audit->platform(
                    $operatorId,
                    $accountId,
                    $requestId,
                    'platform.bootstrap.completed',
                    'platform.bootstrap',
                );

                return new PlatformBootstrapResult($accountId, $operatorId, $roleId);
            });
        } finally {
            $this->releaseBootstrapLock();
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

        return Db::transaction(function () use (
            $platformOperatorId,
            $tenantCode,
            $tenantName,
            $email,
            $initialPassword,
            $ownerDisplayName,
            $requestId,
        ): TenantOwnerCandidateResult {
            $operator = $this->activeOperator($platformOperatorId, true);
            if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $tenantCode) !== 1) {
                throw new \InvalidArgumentException('Invalid tenant code.');
            }
            $now = $this->now();
            $tenant = new Tenant();
            $tenant->save([
                'code' => $tenantCode,
                'name' => $tenantName,
                'display_name' => $tenantName,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $tenantId = (int) $tenant->getKey();
            $roleId = (int) Role::insertGetId([
                'tenant_id' => $tenantId,
                'key' => self::TENANT_OWNER_ROLE,
                'name' => 'Tenant Owner',
                'is_builtin' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $credential = Credential::where('identifier_type', 'email')
                ->where('identifier_normalized', $email->value())
                ->lock(true)
                ->find();
            if (!$credential instanceof Credential) {
                if ($initialPassword === null) {
                    throw new DomainException('Initial password is required for a new account.');
                }
                $account = new Account();
                $account->save(['display_name' => $ownerDisplayName, 'created_at' => $now, 'updated_at' => $now]);
                $accountId = (int) $account->getKey();
                (new Credential())->save([
                    'account_id' => $accountId,
                    'kind' => 'email_password',
                    'identifier_type' => 'email',
                    'identifier_normalized' => $email->value(),
                    'secret_hash' => $this->passwords->hash($initialPassword),
                    'status' => CredentialStatus::Active->value,
                    'verified_at' => $now,
                    'secret_changed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                if ($initialPassword !== null) {
                    throw new DomainException('Password must not be supplied for an existing email.');
                }
                $accountId = (int) $credential->getAttr('account_id');
                $account = Account::where('id', $accountId)->lock(true)->find();
                if (!$account instanceof Account
                    || AccountStatus::from((string) $account->getAttr('status')) !== AccountStatus::Active) {
                    throw new DomainException('Existing owner account is not active.');
                }
            }

            if ($this->memberWithOwnerRoleExists($tenantId, ['pending', 'active'])) {
                throw new DomainException('Tenant owner candidate already exists.');
            }
            $member = new TenantMember();
            $member->save([
                'tenant_id' => $tenantId,
                'account_id' => $accountId,
                'display_name' => $ownerDisplayName,
                'status' => TenantMemberStatus::Pending->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $memberId = (int) $member->getKey();
            MemberRole::insert([
                'tenant_id' => $tenantId,
                'tenant_member_id' => $memberId,
                'role_id' => $roleId,
                'assigned_at' => $now,
            ]);
            TenantMember::withoutGlobalScope()->where('tenant_id', $tenantId)->where('id', $memberId)->update([
                'authorization_revision' => new Raw('authorization_revision + 1'),
                'updated_at' => $now,
            ]);
            $this->audit->platform(
                $operator['id'],
                $operator['account_id'],
                $requestId,
                'tenant.owner-candidate.created',
                'platform.tenant.provision-owner',
                ['tenant_id' => $tenantId, 'member_id' => $memberId],
            );

            return new TenantOwnerCandidateResult($tenantId, $accountId, $memberId, $roleId);
        });
    }

    public function activateTenantOwner(
        int $platformOperatorId,
        int $tenantId,
        int $memberId,
        string $requestId,
    ): void {
        Db::transaction(function () use ($platformOperatorId, $tenantId, $memberId, $requestId): void {
            $operator = $this->activeOperator($platformOperatorId, true);
            $tenant = Tenant::where('id', $tenantId)->lock(true)->find();
            $member = TenantMember::withoutGlobalScope()->where('tenant_id', $tenantId)->where('id', $memberId)
                ->lock(true)->find();
            if (!$tenant instanceof Tenant
                || TenantStatus::from((string) $tenant->getAttr('status')) !== TenantStatus::Provisioning) {
                throw new DomainException('Owner activation requires a provisioning tenant.');
            }
            if (!$member instanceof TenantMember
                || TenantMemberStatus::from((string) $member->getAttr('status')) !== TenantMemberStatus::Pending) {
                throw new DomainException('Pending owner candidate was not found.');
            }
            if (!$this->memberHasOwnerRole($tenantId, $memberId)) {
                throw new DomainException('Owner candidate does not hold the owner role.');
            }
            $account = Account::where('id', (int) $member->getAttr('account_id'))->lock(true)->find();
            $credential = Credential::where('account_id', (int) $member->getAttr('account_id'))
                ->where('status', CredentialStatus::Active->value)->lock(true)->find();
            if (!$account instanceof Account
                || AccountStatus::from((string) $account->getAttr('status')) !== AccountStatus::Active
                || !$credential instanceof Credential) {
                throw new DomainException('Owner account and credential must be active.');
            }
            TenantMemberStatus::Pending->transitionTo(TenantMemberStatus::Active);
            $now = $this->now();
            TenantMember::withoutGlobalScope()->where('tenant_id', $tenantId)->where('id', $memberId)->update([
                'status' => TenantMemberStatus::Active->value,
                'security_revision' => new Raw('security_revision + 1'),
                'authorization_revision' => new Raw('authorization_revision + 1'),
                'joined_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit->tenantPlatformOperator(
                $tenantId,
                $operator['id'],
                $operator['account_id'],
                'tenant.owner-candidate.activated',
                'platform.tenant.provision-owner',
                $requestId,
                ['member_id' => $memberId],
            );
        });
    }

    public function activateTenant(int $platformOperatorId, int $tenantId, string $requestId): void
    {
        Db::transaction(function () use ($platformOperatorId, $tenantId, $requestId): void {
            $operator = $this->activeOperator($platformOperatorId, true);
            $tenant = Tenant::where('id', $tenantId)->lock(true)->find();
            if (!$tenant instanceof Tenant
                || TenantStatus::from((string) $tenant->getAttr('status')) !== TenantStatus::Provisioning) {
                throw new DomainException('Only a provisioning tenant can be activated.');
            }
            if (!$this->memberWithOwnerRoleExists($tenantId, ['active'])) {
                throw new DomainException('Tenant requires an active owner before activation.');
            }
            TenantStatus::Provisioning->transitionTo(TenantStatus::Active);
            $now = $this->now();
            Tenant::where('id', $tenantId)->update([
                'status' => TenantStatus::Active->value,
                'security_revision' => new Raw('security_revision + 1'),
                'revision' => new Raw('revision + 1'),
                'activated_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit->platform(
                $operator['id'],
                $operator['account_id'],
                $requestId,
                'tenant.activated',
                'platform.tenant.lifecycle',
                ['tenant_id' => $tenantId],
            );
        });
    }

    /** @return array{id:int,account_id:int} */
    private function activeOperator(int $operatorId, bool $lock): array
    {
        $operator = PlatformOperator::where('id', $operatorId)->lock($lock)->find();
        if (!$operator instanceof PlatformOperator
            || PlatformOperatorStatus::from((string) $operator->getAttr('status')) !== PlatformOperatorStatus::Active) {
            throw new DomainException('Active platform operator is required.');
        }

        return ['id' => (int) $operator->getAttr('id'), 'account_id' => (int) $operator->getAttr('account_id')];
    }

    private function memberHasOwnerRole(int $tenantId, int $memberId): bool
    {
        $roleId = Role::where('tenant_id', $tenantId)
            ->where('key', self::TENANT_OWNER_ROLE)->where('status', 'active')->value('id');

        return $roleId !== null && MemberRole::where('tenant_id', $tenantId)
            ->where('tenant_member_id', $memberId)
            ->where('role_id', (int) $roleId)
            ->find() !== null;
    }

    /** @param non-empty-list<string> $statuses */
    private function memberWithOwnerRoleExists(int $tenantId, array $statuses): bool
    {
        $roleId = Role::where('tenant_id', $tenantId)
            ->where('key', self::TENANT_OWNER_ROLE)->where('status', 'active')->value('id');
        if ($roleId === null) {
            return false;
        }
        $memberIds = MemberRole::where('tenant_id', $tenantId)
            ->where('role_id', (int) $roleId)->column('tenant_member_id');

        return $memberIds !== [] && TenantMember::withoutGlobalScope()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $memberIds)
            ->whereIn('status', $statuses)
            ->find() !== null;
    }

    /** MySQL advisory locking is the one driver-specific bootstrap primitive. */
    private function acquireBootstrapLock(): void
    {
        $rows = $this->driverConnection()->query(
            'SELECT GET_LOCK(?, 10) AS acquired',
            [self::PLATFORM_BOOTSTRAP_LOCK],
        );
        if ((int) ($rows[0]['acquired'] ?? 0) !== 1) {
            throw new DomainException('Platform bootstrap lock could not be acquired.');
        }
    }

    private function releaseBootstrapLock(): void
    {
        $this->driverConnection()->query('SELECT RELEASE_LOCK(?) AS released', [self::PLATFORM_BOOTSTRAP_LOCK]);
    }

    private function driverConnection(): PDOConnection
    {
        $connection = Db::connect();
        if (!$connection instanceof PDOConnection) {
            throw new DomainException('MySQL bootstrap locking requires ThinkPHP PDOConnection.');
        }

        return $connection;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
