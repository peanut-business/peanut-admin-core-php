<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class ArtifactRevisionRecord extends TenantModel
{
    /** @var string */ protected $name = 'artifact_revision';
}
