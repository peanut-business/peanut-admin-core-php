<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Context;

final readonly class PlatformSystemContext
{
    public function __construct(
        public string $actorKey,
        public string $operation,
        public string $operationId,
    ) {}
}
