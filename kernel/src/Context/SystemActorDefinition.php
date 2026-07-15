<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Context;

use InvalidArgumentException;

final readonly class SystemActorDefinition
{
    /** @param non-empty-list<string> $operations */
    public function __construct(
        public string $key,
        public string $audience,
        public array $operations,
    ) {
        if (!in_array($audience, ['tenant', 'platform'], true)) {
            throw new InvalidArgumentException('Invalid system actor audience.');
        }
    }

    public function allows(string $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }
}
