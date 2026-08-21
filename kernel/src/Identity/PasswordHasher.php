<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Identity;

use RuntimeException;

final class PasswordHasher
{
    public const DEFAULT_MINIMUM_LENGTH = 8;
    public const DEFAULT_MAXIMUM_LENGTH = 1024;

    /** @var array{memory_cost: int, time_cost: int, threads: int} */
    private const OPTIONS = [
        'memory_cost' => 65_536,
        'time_cost' => 4,
        'threads' => 2,
    ];

    public function __construct(
        private int $minimumLength = self::DEFAULT_MINIMUM_LENGTH,
        private int $maximumLength = self::DEFAULT_MAXIMUM_LENGTH,
    ) {
        if ($this->minimumLength < 1 || $this->maximumLength < $this->minimumLength) {
            throw new \InvalidArgumentException('Invalid password policy bounds.');
        }
    }

    public function minimumLength(): int
    {
        return $this->minimumLength;
    }

    public function maximumLength(): int
    {
        return $this->maximumLength;
    }

    public function assertValid(string $plainPassword): void
    {
        $length = strlen($plainPassword);
        if ($length < $this->minimumLength || $length > $this->maximumLength) {
            throw new RuntimeException(sprintf(
                'Password must contain between %d and %d bytes.',
                $this->minimumLength,
                $this->maximumLength,
            ));
        }
    }

    public function hash(string $plainPassword): string
    {
        $this->assertValid($plainPassword);

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
