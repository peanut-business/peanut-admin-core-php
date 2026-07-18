<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use InvalidArgumentException;

final readonly class ModuleKey
{
    private const PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$/D';

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('Invalid module key.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    /** @return non-empty-list<string> */
    public function pascalSegments(): array
    {
        return array_map(
            static fn(string $segment): string => implode('', array_map('ucfirst', explode('-', $segment))),
            explode('.', $this->value),
        );
    }

    public function slug(): string
    {
        return str_replace('.', '-', $this->value);
    }
}
