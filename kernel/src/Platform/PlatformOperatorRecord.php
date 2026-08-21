<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform;

final readonly class PlatformOperatorRecord
{
    public function __construct(
        public int $id,
        public int $accountId,
        public PlatformOperatorStatus $status,
        public int $securityRevision,
    ) {}
}
