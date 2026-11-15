<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Identity;

use InvalidArgumentException;
use RuntimeException;

/** Requirements for new credentials; independent of hashing and existing credential verification. */
class PasswordPolicy
{
    public const DEFAULT_MINIMUM_LENGTH = 6;
    public const DEFAULT_MAXIMUM_LENGTH = 32;

    public function __construct(
        private int $minimumLength = self::DEFAULT_MINIMUM_LENGTH,
        private int $maximumLength = self::DEFAULT_MAXIMUM_LENGTH,
        private bool $requireUppercase = false,
        private bool $requireLowercase = false,
        private bool $requireDigit = false,
        private bool $requireSpecial = false,
    ) {
        if ($minimumLength < 1 || $maximumLength < $minimumLength
            || $maximumLength > PasswordHasher::MAXIMUM_INPUT_BYTES) {
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
        foreach ([
            [$this->requireUppercase, '/[A-Z]/', 'an uppercase ASCII letter (A-Z)'],
            [$this->requireLowercase, '/[a-z]/', 'a lowercase ASCII letter (a-z)'],
            [$this->requireDigit, '/[0-9]/', 'an ASCII digit (0-9)'],
            [$this->requireSpecial, '/[\x21-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e]/', 'a printable ASCII punctuation character (excluding space)'],
        ] as [$required, $pattern, $description]) {
            if ($required && preg_match($pattern, $plainPassword) !== 1) {
                throw new RuntimeException('Password must contain ' . $description . '.');
            }
        }
    }

    /** Public requirements shared with clients; subclasses may override their effective requirements. */
    public function requirements(): array
    {
        return [
            'require_uppercase' => $this->requireUppercase,
            'require_lowercase' => $this->requireLowercase,
            'require_digit' => $this->requireDigit,
            'require_special' => $this->requireSpecial,
        ];
    }
}
