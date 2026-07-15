<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Identity;

final readonly class AccountRecord
{
    public function __construct(
        public int $id,
        public string $displayName,
        public AccountStatus $status,
        public int $securityRevision,
    ) {}
}
