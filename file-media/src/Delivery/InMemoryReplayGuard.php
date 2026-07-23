<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Delivery;

use DateTimeImmutable;

/** Development/test guard. Production Hosts must supply shared atomic storage. */
final class InMemoryReplayGuard implements ReplayGuard
{
    /** @var array<string, int> */
    private array $consumed = [];

    public function consume(string $tokenId, DateTimeImmutable $expiresAt, DateTimeImmutable $now): bool
    {
        foreach ($this->consumed as $id => $expiry) {
            if ($expiry <= $now->getTimestamp()) {
                unset($this->consumed[$id]);
            }
        }
        if (isset($this->consumed[$tokenId]) || $expiresAt <= $now) {
            return false;
        }
        $this->consumed[$tokenId] = $expiresAt->getTimestamp();

        return true;
    }
}
