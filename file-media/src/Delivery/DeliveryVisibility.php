<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Delivery;

enum DeliveryVisibility: string
{
    case Private = 'private';
    case Public = 'public';
}
