<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
