<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Pdo;

use DomainException;
use PeanutAdmin\Kernel\Identity\AccountRecord;
use PeanutAdmin\Kernel\Identity\AccountStatus;
use PeanutAdmin\Kernel\Identity\CredentialRecord;
use PeanutAdmin\Kernel\Identity\CredentialStatus;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\IdentityRepository;

final class PdoIdentityRepository extends PdoRepository implements IdentityRepository
{
    public function createAccount(string $displayName): AccountRecord
    {
        $now = $this->now();
        $this->execute(<<<'SQL'
INSERT INTO pa_account (display_name, created_at, updated_at)
VALUES (:display_name, :created_at, :updated_at)
SQL, [
            'display_name' => $displayName,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->requireAccount($this->lastInsertId());
    }

    public function accountById(int $accountId, bool $forUpdate = false): ?AccountRecord
    {
        $row = $this->fetchOne(
            'SELECT id, display_name, status, security_revision FROM pa_account WHERE id = :id'
            . ($forUpdate ? ' FOR UPDATE' : ''),
            ['id' => $accountId],
        );

        return $row === null ? null : $this->accountRecord($row);
    }

    public function transitionAccount(int $accountId, AccountStatus $next): AccountRecord
    {
        $current = $this->accountById($accountId, true);
        if ($current === null) {
            throw new DomainException('Account was not found.');
        }
        $current->status->transitionTo($next);

        $now = $this->now();
        $closedAt = $next === AccountStatus::Closed ? $now : null;
        $this->execute(<<<'SQL'
UPDATE pa_account
SET status = :status,
    security_revision = security_revision + 1,
    locked_until = CASE WHEN :status_unlock = 'active' THEN NULL ELSE locked_until END,
    closed_at = COALESCE(:closed_at, closed_at),
    updated_at = :updated_at
WHERE id = :id AND security_revision = :expected_revision
SQL, [
            'status' => $next->value,
            'status_unlock' => $next->value,
            'closed_at' => $closedAt,
            'updated_at' => $now,
            'id' => $accountId,
            'expected_revision' => $current->securityRevision,
        ]);

        return $this->requireAccount($accountId);
    }

    public function credentialByEmail(EmailAddress $email, bool $forUpdate = false): ?CredentialRecord
    {
        $row = $this->fetchOne(
            'SELECT id, account_id, identifier_normalized, secret_hash, status, revision'
            . ' FROM pa_credential WHERE identifier_type = \'email\' AND identifier_normalized = :email'
            . ($forUpdate ? ' FOR UPDATE' : ''),
            ['email' => $email->value()],
        );

        return $row === null ? null : $this->credentialRecord($row);
    }

    public function activeCredentialForAccount(int $accountId, bool $forUpdate = false): ?CredentialRecord
    {
        $row = $this->fetchOne(
            'SELECT id, account_id, identifier_normalized, secret_hash, status, revision'
            . ' FROM pa_credential WHERE account_id = :account_id AND status = \'active\''
            . ' ORDER BY id LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : ''),
            ['account_id' => $accountId],
        );

        return $row === null ? null : $this->credentialRecord($row);
    }

    public function createEmailCredential(
        int $accountId,
        EmailAddress $email,
        string $secretHash,
    ): CredentialRecord {
        $now = $this->now();
        $this->execute(<<<'SQL'
INSERT INTO pa_credential (
    account_id, kind, identifier_type, identifier_normalized, secret_hash,
    verified_at, secret_changed_at, created_at, updated_at
) VALUES (
    :account_id, 'email_password', 'email', :email, :secret_hash,
    :verified_at, :secret_changed_at, :created_at, :updated_at
)
SQL, [
            'account_id' => $accountId,
            'email' => $email->value(),
            'secret_hash' => $secretHash,
            'verified_at' => $now,
            'secret_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->requireCredential($this->lastInsertId());
    }

    public function transitionCredential(int $credentialId, CredentialStatus $next): CredentialRecord
    {
        $current = $this->requireCredential($credentialId, true);
        $current->status->transitionTo($next);

        $now = $this->now();
        $this->execute(<<<'SQL'
UPDATE pa_credential c
JOIN pa_account a ON a.id = c.account_id
SET c.status = :status,
    c.revision = c.revision + 1,
    c.locked_until = CASE WHEN :status_unlock = 'active' THEN NULL ELSE c.locked_until END,
    c.revoked_at = CASE WHEN :status_revoke = 'revoked' THEN :revoked_at ELSE c.revoked_at END,
    c.updated_at = :credential_updated_at,
    a.security_revision = a.security_revision + 1,
    a.updated_at = :account_updated_at
WHERE c.id = :id AND c.revision = :expected_revision
SQL, [
            'status' => $next->value,
            'status_unlock' => $next->value,
            'status_revoke' => $next->value,
            'revoked_at' => $now,
            'credential_updated_at' => $now,
            'account_updated_at' => $now,
            'id' => $credentialId,
            'expected_revision' => $current->revision,
        ]);

        return $this->requireCredential($credentialId);
    }

    private function requireAccount(int $accountId, bool $forUpdate = false): AccountRecord
    {
        $record = $this->accountById($accountId, $forUpdate);
        if ($record === null) {
            throw new DomainException('Account was not found.');
        }

        return $record;
    }

    private function requireCredential(int $credentialId, bool $forUpdate = false): CredentialRecord
    {
        $row = $this->fetchOne(
            'SELECT id, account_id, identifier_normalized, secret_hash, status, revision'
            . ' FROM pa_credential WHERE id = :id'
            . ($forUpdate ? ' FOR UPDATE' : ''),
            ['id' => $credentialId],
        );
        if ($row === null) {
            throw new DomainException('Credential was not found.');
        }

        return $this->credentialRecord($row);
    }

    /** @param array<string, mixed> $row */
    private function accountRecord(array $row): AccountRecord
    {
        return new AccountRecord(
            (int) $row['id'],
            (string) $row['display_name'],
            AccountStatus::from((string) $row['status']),
            (int) $row['security_revision'],
        );
    }

    /** @param array<string, mixed> $row */
    private function credentialRecord(array $row): CredentialRecord
    {
        return new CredentialRecord(
            (int) $row['id'],
            (int) $row['account_id'],
            EmailAddress::fromString((string) $row['identifier_normalized']),
            (string) $row['secret_hash'],
            CredentialStatus::from((string) $row['status']),
            (int) $row['revision'],
        );
    }
}
