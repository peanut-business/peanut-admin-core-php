<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Identity;

use InvalidArgumentException;
use RuntimeException;

/** Requirements for new credentials; independent of hashing and existing credential verification. */
class PasswordPolicy
{
    public const DEFAULT_MINIMUM_LENGTH = 6;
    public const DEFAULT_MAXIMUM_LENGTH = 1024;

    public function __construct(
        private int $minimumLength = self::DEFAULT_MINIMUM_LENGTH,
        private int $maximumLength = self::DEFAULT_MAXIMUM_LENGTH,
    ) {
        if ($minimumLength < 1 || $maximumLength < $minimumLength) {
            throw new InvalidArgumentException('Invalid password policy bounds.');
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
}
