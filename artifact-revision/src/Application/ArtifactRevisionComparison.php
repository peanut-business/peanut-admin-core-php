<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Application;

final readonly class ArtifactRevisionComparison
{
    public function __construct(
        public string $artifactType,
        public string $artifactKey,
        public string $leftRevisionKey,
        public string $rightRevisionKey,
        public string $relationship,
    ) {
        if (!in_array($relationship, ['same', 'ancestor', 'descendant', 'diverged'], true)) {
            throw ArtifactRevisionException::internal();
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'artifact_type' => $this->artifactType,
            'artifact_key' => $this->artifactKey,
            'left_revision_key' => $this->leftRevisionKey,
            'right_revision_key' => $this->rightRevisionKey,
            'relationship' => $this->relationship,
        ];
    }
}
