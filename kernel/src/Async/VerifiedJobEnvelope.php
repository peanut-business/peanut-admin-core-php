<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Async;

use PeanutAdmin\Kernel\Context\RequestedTargetSet;

final readonly class VerifiedJobEnvelope
{
    /** @param list<RequestedTargetSet> $requestedTargets */
    public function __construct(
        public int $tenantId,
        public int $accountId,
        public int $memberId,
        public string $resourceKey,
        public string $operation,
        public array $requestedTargets,
        public string $operationId,
        public string $traceId,
    ) {}
}
