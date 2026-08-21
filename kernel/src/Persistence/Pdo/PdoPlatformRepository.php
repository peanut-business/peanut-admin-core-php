<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Pdo;

use DomainException;
use PeanutAdmin\Kernel\Platform\PlatformOperatorRecord;
use PeanutAdmin\Kernel\Platform\PlatformOperatorStatus;
use PeanutAdmin\Kernel\Platform\PlatformRepository;

final class PdoPlatformRepository extends PdoRepository implements PlatformRepository
{
    private const BOOTSTRAP_LOCK = 'peanut-admin:bootstrap:platform-owner';

    public function acquireBootstrapLock(): void
    {
        $row = $this->fetchOne('SELECT GET_LOCK(:lock_name, 10) AS acquired', [
            'lock_name' => self::BOOTSTRAP_LOCK,
        ]);
        if ($row === null || (int) $row['acquired'] !== 1) {
            throw new DomainException('Platform bootstrap lock could not be acquired.');
        }
    }

    public function releaseBootstrapLock(): void
    {
        $this->fetchOne('SELECT RELEASE_LOCK(:lock_name) AS released', [
            'lock_name' => self::BOOTSTRAP_LOCK,
        ]);
    }

    public function operatorCount(): int
    {
        $row = $this->fetchOne('SELECT COUNT(*) AS aggregate FROM pa_platform_operator');

        return $row === null ? 0 : (int) $row['aggregate'];
    }

    public function createOperator(int $accountId, string $displayName): PlatformOperatorRecord
    {
        $now = $this->now();
        $this->execute(<<<'SQL'
INSERT INTO pa_platform_operator (account_id, display_name, created_at, updated_at)
VALUES (:account_id, :display_name, :created_at, :updated_at)
SQL, [
            'account_id' => $accountId,
            'display_name' => $displayName,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->requireOperator($this->lastInsertId());
    }

    public function operatorById(int $operatorId, bool $forUpdate = false): ?PlatformOperatorRecord
    {
        $row = $this->fetchOne(
            'SELECT id, account_id, status, security_revision FROM pa_platform_operator'
            . ' WHERE id = :id'
            . ($forUpdate ? ' FOR UPDATE' : ''),
            ['id' => $operatorId],
        );

        return $row === null ? null : new PlatformOperatorRecord(
            (int) $row['id'],
            (int) $row['account_id'],
            PlatformOperatorStatus::from((string) $row['status']),
            (int) $row['security_revision'],
        );
    }

    public function transitionOperator(
        int $operatorId,
        PlatformOperatorStatus $next,
    ): PlatformOperatorRecord {
        $current = $this->requireOperator($operatorId, true);
        $current->status->transitionTo($next);
        if (
            $current->status === PlatformOperatorStatus::Active
            && $next !== PlatformOperatorStatus::Active
            && $this->activeOperatorCountForUpdate() <= 1
        ) {
            throw new DomainException('The last active platform operator cannot be suspended or closed.');
        }

        $now = $this->now();
        $this->execute(<<<'SQL'
UPDATE pa_platform_operator
SET status = :status,
    security_revision = security_revision + 1,
    suspended_at = CASE WHEN :suspended_status = 'suspended' THEN :suspended_at ELSE suspended_at END,
    closed_at = CASE WHEN :closed_status = 'closed' THEN :closed_at ELSE closed_at END,
    updated_at = :updated_at
WHERE id = :id AND security_revision = :expected_revision
SQL, [
            'status' => $next->value,
            'suspended_status' => $next->value,
            'closed_status' => $next->value,
            'suspended_at' => $now,
            'closed_at' => $now,
            'updated_at' => $now,
            'id' => $operatorId,
            'expected_revision' => $current->securityRevision,
        ]);

        return $this->requireOperator($operatorId);
    }

    public function createBuiltinRole(string $key, string $name): int
    {
        $now = $this->now();
        $this->execute(<<<'SQL'
INSERT INTO pa_platform_role (`key`, name, is_builtin, created_at, updated_at)
VALUES (:role_key, :name, 1, :created_at, :updated_at)
SQL, [
            'role_key' => $key,
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->lastInsertId();
    }

    public function assignRole(int $operatorId, int $roleId): void
    {
        $now = $this->now();
        $this->execute(<<<'SQL'
INSERT INTO pa_platform_operator_role (
    platform_operator_id, platform_role_id, assigned_at
) VALUES (
    :operator_id, :role_id, :assigned_at
)
SQL, [
            'operator_id' => $operatorId,
            'role_id' => $roleId,
            'assigned_at' => $now,
        ]);
        $this->execute(<<<'SQL'
UPDATE pa_platform_operator
SET security_revision = security_revision + 1, updated_at = :updated_at
WHERE id = :operator_id
SQL, ['updated_at' => $now, 'operator_id' => $operatorId]);
    }

    private function requireOperator(int $operatorId, bool $forUpdate = false): PlatformOperatorRecord
    {
        $operator = $this->operatorById($operatorId, $forUpdate);
        if ($operator === null) {
            throw new DomainException('Platform operator was not found.');
        }

        return $operator;
    }

    private function activeOperatorCountForUpdate(): int
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT id FROM pa_platform_operator WHERE status = 'active' ORDER BY id FOR UPDATE
SQL);
        if ($statement === false) {
            throw new DomainException('Could not lock active platform operators.');
        }

        return count($statement->fetchAll());
    }
}
