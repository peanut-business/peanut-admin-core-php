<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Delivery;

use DateTimeImmutable;

interface ReplayGuard
{
    public function consume(string $tokenId, DateTimeImmutable $expiresAt, DateTimeImmutable $now): bool;
}
