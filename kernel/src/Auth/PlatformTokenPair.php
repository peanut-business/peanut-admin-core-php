<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;

final readonly class PlatformTokenPair
{
    public function __construct(
        public RawToken $access,
        public RawToken $refresh,
        public DateTimeImmutable $accessExpiresAt,
        public DateTimeImmutable $refreshExpiresAt,
    ) {}
}
