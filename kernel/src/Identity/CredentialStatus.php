<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Identity;

use DomainException;

enum CredentialStatus: string
{
    case Active = 'active';
    case Locked = 'locked';
    case Revoked = 'revoked';

    public function transitionTo(self $next): self
    {
        $allowed = match ($this) {
            self::Active => [self::Locked, self::Revoked],
            self::Locked => [self::Active, self::Revoked],
            self::Revoked => [],
        };

        if (!in_array($next, $allowed, true)) {
            throw new DomainException("Credential cannot transition from {$this->value} to {$next->value}.");
        }

        return $next;
    }
}
