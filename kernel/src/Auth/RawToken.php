<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use SensitiveParameter;

final readonly class RawToken
{
    public function __construct(#[SensitiveParameter] private string $value) {}

    public function expose(): string
    {
        return $this->value;
    }

    public function hash(): string
    {
        return hash('sha256', $this->value);
    }
}
