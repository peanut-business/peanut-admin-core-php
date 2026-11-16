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
        // The public requirements shown to clients must be the same effective
        // requirements enforced here, including subclass overrides.
        $requirements = $this->requirements();
        foreach ([
            ['require_uppercase', '/[A-Z]/', 'an uppercase ASCII letter (A-Z)'],
            ['require_lowercase', '/[a-z]/', 'a lowercase ASCII letter (a-z)'],
            ['require_digit', '/[0-9]/', 'an ASCII digit (0-9)'],
            ['require_special', '/[\x21-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e]/', 'a printable ASCII punctuation character (excluding space)'],
        ] as [$key, $pattern, $description]) {
            if (!array_key_exists($key, $requirements) || !is_bool($requirements[$key])) {
                throw new InvalidArgumentException('Invalid password requirement: ' . $key);
            }
            if ($requirements[$key] && preg_match($pattern, $plainPassword) !== 1) {
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
