<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision;

final class Package
{
    public const MODULE_KEY = 'peanut.artifact-revision';
    public const VERSION = '0.1.0-alpha.8';

    public const CREATE_OPERATION = 'artifact-revision.create';
    public const FINALIZE_OPERATION = 'artifact-revision.finalize';

    private function __construct() {}
}
