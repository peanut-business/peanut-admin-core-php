<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Policy;

interface PolicyRepository
{
    public function revision(int $tenantId, int $memberId, int $operationId): PolicyRevision;

    /** @param list<int> $operationIds @return array<int, PolicyRevision> */
    public function revisions(int $tenantId, int $memberId, array $operationIds): array;

    public function load(int $tenantId, int $memberId, int $operationId): EffectivePolicySet;

    /** @param list<int> $operationIds @return array<int, EffectivePolicySet> */
    public function loadMany(int $tenantId, int $memberId, array $operationIds): array;
}
