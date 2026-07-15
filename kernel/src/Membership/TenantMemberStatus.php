<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Membership;

use DomainException;

enum TenantMemberStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Left = 'left';

    public function transitionTo(self $next): self
    {
        $allowed = match ($this) {
            self::Pending => [self::Active, self::Left],
            self::Active => [self::Suspended, self::Left],
            self::Suspended => [self::Active, self::Left],
            self::Left => [self::Pending],
        };

        if (!in_array($next, $allowed, true)) {
            throw new DomainException("Tenant member cannot transition from {$this->value} to {$next->value}.");
        }

        return $next;
    }
}
