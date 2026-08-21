<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform;

use DomainException;

enum PlatformOperatorStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function transitionTo(self $next): self
    {
        $allowed = match ($this) {
            self::Active => [self::Suspended, self::Closed],
            self::Suspended => [self::Active, self::Closed],
            self::Closed => [],
        };

        if (!in_array($next, $allowed, true)) {
            throw new DomainException("Platform operator cannot transition from {$this->value} to {$next->value}.");
        }

        return $next;
    }
}
