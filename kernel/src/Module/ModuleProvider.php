<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

interface ModuleProvider
{
    public function moduleKey(): string;
}
