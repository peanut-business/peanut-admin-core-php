<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Delivery;

enum ReplayMode: string
{
    case SingleUse = 'single_use';
    case Bounded = 'bounded';
}
