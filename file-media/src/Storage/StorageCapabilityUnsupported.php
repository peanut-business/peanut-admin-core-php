<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

/** A missing provider primitive must never be treated as a completed restriction. */
final class StorageCapabilityUnsupported extends \RuntimeException
{
    public function __construct(public readonly string $capability)
    {
        parent::__construct('STORAGE_CAPABILITY_UNSUPPORTED: ' . $capability);
    }
}
