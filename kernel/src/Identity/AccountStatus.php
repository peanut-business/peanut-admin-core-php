<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Identity;

use DomainException;

enum AccountStatus: string
{
    case Active = 'active';
    case Locked = 'locked';
    case Disabled = 'disabled';
    case Closed = 'closed';

    public function transitionTo(self $next): self
    {
        $allowed = match ($this) {
            self::Active => [self::Locked, self::Disabled, self::Closed],
            self::Locked => [self::Active, self::Disabled, self::Closed],
            self::Disabled => [self::Active, self::Closed],
            self::Closed => [],
        };

        if (!in_array($next, $allowed, true)) {
            throw new DomainException("Account cannot transition from {$this->value} to {$next->value}.");
        }

        return $next;
    }
}
