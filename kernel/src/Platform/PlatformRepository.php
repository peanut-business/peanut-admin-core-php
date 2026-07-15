<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform;

interface PlatformRepository
{
    public function acquireBootstrapLock(): void;

    public function releaseBootstrapLock(): void;

    public function operatorCount(): int;

    public function createOperator(int $accountId, string $displayName): PlatformOperatorRecord;

    public function operatorById(int $operatorId, bool $forUpdate = false): ?PlatformOperatorRecord;

    public function transitionOperator(
        int $operatorId,
        PlatformOperatorStatus $next,
    ): PlatformOperatorRecord;

    public function createBuiltinRole(string $key, string $name): int;

    public function assignRole(int $operatorId, int $roleId): void;
}
