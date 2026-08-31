<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

interface ModuleProvider
{
    public function moduleKey(): string;

    /** @return array<class-string, class-string> */
    public function bindings(): array;
}
