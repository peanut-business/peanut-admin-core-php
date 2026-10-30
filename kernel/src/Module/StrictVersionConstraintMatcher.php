<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use Composer\Semver\Semver;
use UnexpectedValueException;

/** Composer constraints; malformed versions are incompatible rather than a compiler failure. */
final class StrictVersionConstraintMatcher
{
    public function matches(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (UnexpectedValueException) {
            return false;
        }
    }
}
