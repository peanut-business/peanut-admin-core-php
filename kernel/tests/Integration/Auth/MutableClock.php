<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Auth;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\Clock;

final class MutableClock implements Clock
{
    public function __construct(private DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }

    public function advance(string $modifier): void
    {
        $this->time = $this->time->modify($modifier);
    }
}
