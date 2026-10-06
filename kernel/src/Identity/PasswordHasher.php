<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Identity;

class PasswordHasher
{
    /** Technical input ceiling, independent of new-password requirements. */
    public const MAXIMUM_INPUT_BYTES = 4096;
    /** @var array{memory_cost: int, time_cost: int, threads: int} */
    private const OPTIONS = [
        'memory_cost' => 65_536,
        'time_cost' => 4,
        'threads' => 2,
    ];

    public function hash(string $plainPassword): string
    {
        if (strlen($plainPassword) > self::MAXIMUM_INPUT_BYTES) {
            throw new \RuntimeException('Password exceeds the hashing input ceiling.');
        }
        return password_hash($plainPassword, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public function verify(string $plainPassword, string $hash): bool
    {
        if (strlen($plainPassword) > self::MAXIMUM_INPUT_BYTES) {
            return false;
        }
        return password_verify($plainPassword, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::OPTIONS);
    }
}
