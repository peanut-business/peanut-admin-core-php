<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Policy;

interface PolicyRepository
{
    public function revision(int $tenantId, int $memberId, int $operationId): PolicyRevision;

    public function load(int $tenantId, int $memberId, int $operationId): EffectivePolicySet;
}
