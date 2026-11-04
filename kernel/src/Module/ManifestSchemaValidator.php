<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

interface ManifestSchemaValidator
{
    /** Enforce the complete bundled module-manifest.schema.json, including the expanded catalog. */
    public function assertValid(object $manifest): void;
}
