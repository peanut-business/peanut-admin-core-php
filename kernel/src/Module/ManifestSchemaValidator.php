<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

interface ManifestSchemaValidator
{
    public function assertValid(object $manifest): void;
}
