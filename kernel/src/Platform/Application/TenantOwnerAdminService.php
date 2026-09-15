<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Audit\Model\PlatformAuditEventRecord;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Persistence\Model\Account;
use PeanutAdmin\Kernel\Persistence\Model\Credential;
use PeanutAdmin\Kernel\Persistence\Model\MemberRole;
use PeanutAdmin\Kernel\Persistence\Model\PlatformOperator;
use PeanutAdmin\Kernel\Persistence\Model\Role;
use PeanutAdmin\Kernel\Persistence\Model\Tenant;
use PeanutAdmin\Kernel\Persistence\Model\TenantMember;
use Throwable;
use think\db\Raw;
use think\facade\Db;

final readonly class TenantOwnerAdminService
{
    public function __construct(
        private AuditService $audit,
        private PasswordHasher $passwords = new PasswordHasher(),
    ) {}

    /** @return array<string, mixed> */
    public function createCandidate(
        PlatformContext $actor,
        int $tenantId,
        string $email,
        string $displayName,
        ?string $initialPassword,
    ): array {
        try {
            $identifier = EmailAddress::fromString($email)->value();
        } catch (InvalidArgumentException) {
            throw AdminAccessException::invalid('EMAIL_INVALID', 'The email address is invalid.');
        }

        return $this->transaction(function () use ($actor, $tenantId, $identifier, $displayName, $initialPassword): array {
            $this->requireOperator($actor);
            $this->requireProvisioningTenant($tenantId);
            $ownerRoleId = Role::where('tenant_id', $tenantId)->where('key', 'core.tenant-owner')
                ->where('is_builtin', 1)->where('status', 'active')->lock(true)->value('id');
            if ($ownerRoleId === null) {
                throw AdminAccessException::conflict(
                    'TENANT_OWNER_ROLE_MISSING',
                    'The provisioning tenant does not contain its built-in owner role.',
                );
            }
            if ($this->ownerCandidateCount($tenantId) !== 0) {
                throw AdminAccessException::conflict(
                    'TENANT_OWNER_CANDIDATE_EXISTS',
                    'A pending or active owner candidate already exists.',
                );
            }
            $credential = Credential::where('identifier_type', 'email')
                ->where('identifier_normalized', $identifier)->lock(true)->field('id,account_id,status')->find()?->toArray();
            if ($credential === null) {
                if ($initialPassword === null || $initialPassword === '') {
                    throw AdminAccessException::invalid(
                        'INITIAL_PASSWORD_REQUIRED',
                        'An initial password is required for a new account.',
                    );
                }
                $accountId = $this->createAccountAndCredential($identifier, $displayName, $initialPassword);
            } else {
                if ($initialPassword !== null) {
                    throw AdminAccessException::invalid(
                        'INITIAL_PASSWORD_NOT_ALLOWED',
                        'An existing account credential cannot be overwritten.',
                    );
                }
                if ($credential['status'] !== 'active') {
                    throw AdminAccessException::conflict('CREDENTIAL_INACTIVE', 'The account credential is inactive.');
                }
                $accountId = (int) $credential['account_id'];
                if (Account::where('id', $accountId)->lock(true)->value('status') !== 'active') {
                    throw AdminAccessException::conflict('ACCOUNT_INACTIVE', 'The account is inactive.');
                }
            }
            if (TenantMember::where('tenant_id', $tenantId)->where('account_id', $accountId)
                ->lock(true)->value('id') !== null) {
                throw AdminAccessException::conflict(
                    'TENANT_MEMBER_ALREADY_EXISTS',
                    'The account already has a member record in this tenant.',
                );
            }
            $now = $this->now();
            $memberId = (int) TenantMember::insertGetId([
                'tenant_id' => $tenantId,
                'account_id' => $accountId,
                'display_name' => $displayName,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            MemberRole::insert([
                'tenant_id' => $tenantId,
                'tenant_member_id' => $memberId,
                'role_id' => (int) $ownerRoleId,
                'assigned_at' => $now,
            ]);
            TenantMember::where('tenant_id', $tenantId)->where('id', $memberId)->update([
                'authorization_revision' => new Raw('authorization_revision + 1'),
                'updated_at' => $now,
            ]);
            Tenant::where('id', $tenantId)->update([
                'authorization_revision' => new Raw('authorization_revision + 1'),
                'updated_at' => $now,
            ]);
            $this->audit->platform(
                $actor->operatorId,
                $actor->accountId,
                $actor->requestId,
                'tenant.owner-candidate.created',
                'platform.tenant.provision-owner',
                ['tenant_id' => (string) $tenantId, 'member_id' => (string) $memberId],
            );

            return $this->candidate($tenantId, $memberId);
        });
    }

    /** @return array<string, mixed> */
    public function activateCandidate(
        PlatformContext $actor,
        int $tenantId,
        int $memberId,
        int $expectedRevision,
        string $idempotencyKey,
        string $changeReason,
    ): array {
        if ($idempotencyKey === '') {
            throw new AdminAccessException('IDEMPOTENCY_KEY_REQUIRED', 428, 'Idempotency-Key is required.');
        }
        if (trim($changeReason) === '') {
            throw AdminAccessException::invalid('CHANGE_REASON_REQUIRED', 'A change reason is required.');
        }
        $idempotencyHash = hash('sha256', implode('|', [
            $idempotencyKey, (string) $tenantId, (string) $memberId, (string) $expectedRevision, $changeReason,
        ]));

        return $this->transaction(function () use (
            $actor, $tenantId, $memberId, $expectedRevision, $idempotencyHash, $changeReason,
        ): array {
            $this->requireOperator($actor);
            $this->requireProvisioningTenant($tenantId);
            $member = TenantMember::alias('member')
                ->join('account account', 'account.id = member.account_id')
                ->where('member.tenant_id', $tenantId)->where('member.id', $memberId)
                ->field(['member.*', 'account.status' => 'account_status'])->lock(true)->find()?->toArray();
            if ($member === null) {
                throw AdminAccessException::notFound();
            }
            if ($member['status'] === 'active') {
                if ($this->activationWasApplied($actor->operatorId, $tenantId, $memberId, $idempotencyHash)) {
                    return $this->candidate($tenantId, $memberId);
                }
                throw AdminAccessException::conflict('OWNER_ALREADY_ACTIVE', 'The owner candidate is already active.');
            }
            if ($member['status'] !== 'pending') {
                throw AdminAccessException::conflict('OWNER_CANDIDATE_STATUS_INVALID', 'Only a pending owner can be activated.');
            }
            if ((int) $member['authorization_revision'] !== $expectedRevision) {
                throw AdminAccessException::revisionMismatch();
            }
            if ($member['account_status'] !== 'active' || !$this->activeCredentialExists((int) $member['account_id'])) {
                throw AdminAccessException::conflict('OWNER_ACCOUNT_INACTIVE', 'The owner account and credential must be active.');
            }
            if (!$this->memberHasOwnerRole($tenantId, $memberId)) {
                throw AdminAccessException::conflict('TENANT_OWNER_ROLE_MISSING', 'The candidate does not hold the owner role.');
            }
            $now = $this->now();
            if (TenantMember::where('tenant_id', $tenantId)->where('id', $memberId)
                ->where('status', 'pending')->where('authorization_revision', $expectedRevision)->update([
                    'status' => 'active',
                    'joined_at' => $now,
                    'security_revision' => new Raw('security_revision + 1'),
                    'authorization_revision' => new Raw('authorization_revision + 1'),
                    'updated_at' => $now,
                ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            Tenant::where('id', $tenantId)->update([
                'authorization_revision' => new Raw('authorization_revision + 1'),
                'updated_at' => $now,
            ]);
            $metadata = [
                'tenant_id' => (string) $tenantId,
                'member_id' => (string) $memberId,
                'idempotency_hash' => $idempotencyHash,
                'change_reason' => $changeReason,
            ];
            $this->audit->platform(
                $actor->operatorId,
                $actor->accountId,
                $actor->requestId,
                'tenant.owner-candidate.activated',
                'platform.tenant.provision-owner',
                $metadata,
            );
            $this->audit->tenantPlatformOperator(
                $tenantId,
                $actor->operatorId,
                $actor->accountId,
                'tenant.owner-candidate.activated',
                'platform.tenant.provision-owner',
                $actor->requestId,
                $metadata,
            );

            return $this->candidate($tenantId, $memberId);
        });
    }

    /** @return array<string, mixed> */
    private function candidate(int $tenantId, int $memberId): array
    {
        $row = TenantMember::where('tenant_id', $tenantId)->where('id', $memberId)
            ->field('id,account_id,display_name,status,security_revision,authorization_revision')->find()?->toArray();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }

        $roleId = MemberRole::alias('membership')
            ->join('role role', "role.tenant_id=membership.tenant_id AND role.id=membership.role_id AND role.`key`='core.tenant-owner' AND role.is_builtin=1")
            ->where('membership.tenant_id', $tenantId)->where('membership.tenant_member_id', $memberId)
            ->value('membership.role_id');
        if ($roleId === null) {
            throw AdminAccessException::conflict('TENANT_OWNER_ROLE_MISSING', 'The candidate does not hold the owner role.');
        }

        return [
            'tenant_id' => (string) $tenantId,
            'member' => [
                'id' => (string) $row['id'],
                'account_id' => (string) $row['account_id'],
                'role_id' => (string) $roleId,
                'display_name' => $row['display_name'],
                'status' => $row['status'],
                'security_revision' => (string) $row['security_revision'],
                'revision' => (string) $row['authorization_revision'],
                'role_keys' => ['core.tenant-owner'],
            ],
        ];
    }

    private function requireOperator(PlatformContext $actor): void
    {
        if (PlatformOperator::where('id', $actor->operatorId)->where('account_id', $actor->accountId)
            ->where('status', 'active')->lock(true)->value('id') === null) {
            throw new AdminAccessException('PLATFORM_OPERATOR_INVALID', 403, 'An active platform operator is required.');
        }
    }

    private function requireProvisioningTenant(int $tenantId): void
    {
        if (Tenant::where('id', $tenantId)->where('status', 'provisioning')->lock(true)->value('id') === null) {
            throw AdminAccessException::conflict(
                'TENANT_NOT_PROVISIONING',
                'Owner provisioning is only available while the tenant is provisioning.',
            );
        }
    }

    private function ownerCandidateCount(int $tenantId): int
    {
        return (int) TenantMember::alias('member')
            ->join(
                'member_role member_role',
                'member_role.tenant_id = member.tenant_id AND member_role.tenant_member_id = member.id',
            )->join('role role', 'role.tenant_id = member_role.tenant_id AND role.id = member_role.role_id')
            ->where('member.tenant_id', $tenantId)->whereIn('member.status', ['pending', 'active'])
            ->where('role.key', 'core.tenant-owner')->where('role.is_builtin', 1)->where('role.status', 'active')
            ->distinct(true)->count('member.id');
    }

    private function createAccountAndCredential(string $identifier, string $displayName, string $password): int
    {
        $now = $this->now();
        $accountId = (int) Account::insertGetId([
            'display_name' => $displayName, 'created_at' => $now, 'updated_at' => $now,
        ]);
        Credential::insert([
            'account_id' => $accountId, 'kind' => 'email_password', 'identifier_type' => 'email',
            'identifier_normalized' => $identifier, 'secret_hash' => $this->passwords->hash($password),
            'verified_at' => $now, 'secret_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $accountId;
    }

    private function memberHasOwnerRole(int $tenantId, int $memberId): bool
    {
        return MemberRole::alias('member_role')
            ->join('role role', 'role.tenant_id = member_role.tenant_id AND role.id = member_role.role_id')
            ->where('member_role.tenant_id', $tenantId)->where('member_role.tenant_member_id', $memberId)
            ->where('role.key', 'core.tenant-owner')->where('role.is_builtin', 1)->where('role.status', 'active')
            ->value('member_role.id') !== null;
    }

    private function activeCredentialExists(int $accountId): bool
    {
        return Credential::where('account_id', $accountId)->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->whereOr('expires_at', '>', new Raw('UTC_TIMESTAMP(3)'));
            })->value('id') !== null;
    }

    private function activationWasApplied(
        int $operatorId,
        int $tenantId,
        int $memberId,
        string $idempotencyHash,
    ): bool {
        $rows = PlatformAuditEventRecord::where('operator_id', $operatorId)
            ->where('event_type', 'tenant.owner-candidate.activated')
            ->where('target_type', 'tenant-owner-candidate')->where('target_id', (string) $memberId)
            ->column('metadata_json');
        foreach ($rows as $json) {
            try {
                $metadata = json_decode((string) $json, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (is_array($metadata)
                && ($metadata['tenant_id'] ?? null) === (string) $tenantId
                && ($metadata['idempotency_hash'] ?? null) === $idempotencyHash) {
                return true;
            }
        }

        return false;
    }

    /** @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation): mixed
    {
        try {
            return Db::transaction($operation);
        } catch (Throwable $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw AdminAccessException::conflict(
                    'TENANT_OWNER_CANDIDATE_CONFLICT',
                    'The owner candidate conflicts with an existing relation.',
                );
            }
            throw $exception;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
