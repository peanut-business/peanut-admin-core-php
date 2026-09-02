<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use Closure;

interface ModuleProvider
{
    public function moduleKey(): string;

    /** @return array<class-string, class-string|Closure> */
    public function bindings(): array;
}
