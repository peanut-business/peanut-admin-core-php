<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

interface ContractInspector
{
    public function classExists(string $class): bool;

    public function implements(string $class, string $contract): bool;
}
