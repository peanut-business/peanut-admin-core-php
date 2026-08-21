<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

interface VersionConstraintMatcher
{
    public function matches(string $version, string $constraint): bool;
}
