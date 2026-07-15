<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Identity;

use RuntimeException;

final class PasswordHasher
{
    /** @var array{memory_cost: int, time_cost: int, threads: int} */
    private const OPTIONS = [
        'memory_cost' => 65_536,
        'time_cost' => 4,
        'threads' => 2,
    ];

    public function hash(string $plainPassword): string
    {
        if (strlen($plainPassword) < 12) {
            throw new RuntimeException('Password does not meet the minimum length.');
        }

        return password_hash($plainPassword, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public function verify(string $plainPassword, string $hash): bool
    {
        return password_verify($plainPassword, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::OPTIONS);
    }
}
