<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Scheduling;

final readonly class ScheduleWindow
{
    public function __construct(
        public int $lastRunAt,
        public int $now,
    ) {
        if ($lastRunAt < 0 || $now < 0) {
            throw new \InvalidArgumentException('Schedule timestamps must not be negative.');
        }
    }

    public function isInitial(): bool
    {
        return $this->lastRunAt === 0;
    }

    public function isDue(int $nextRunAt): bool
    {
        if ($nextRunAt < 0) {
            throw new \InvalidArgumentException('The next schedule timestamp must not be negative.');
        }

        return $nextRunAt <= $this->now;
    }
}
