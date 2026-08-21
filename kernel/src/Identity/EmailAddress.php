<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Identity;

use InvalidArgumentException;

final readonly class EmailAddress
{
    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email address.');
        }

        return new self($normalized);
    }

    public function value(): string
    {
        return $this->value;
    }
}
