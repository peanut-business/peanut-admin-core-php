<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Authorization;

use Closure;

final readonly class RevalidatingReadContract
{
    /** @var Closure(): list<array<string, mixed>> */
    private Closure $execute;

    /** @param callable(): list<array<string, mixed>> $execute */
    public function __construct(callable $execute)
    {
        $this->execute = Closure::fromCallable($execute);
    }

    /** @return list<array<string, mixed>> */
    public function execute(): array
    {
        return ($this->execute)();
    }
}
