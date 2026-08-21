<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

final readonly class StoredObjectMetadata
{
    public function __construct(
        public string $providerKey,
        public string $storageKey,
        public int $sizeBytes,
        public string $sha256,
    ) {
        if ($providerKey === '' || $storageKey === '' || $sizeBytes < 1 || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) {
            throw new \InvalidArgumentException('Stored object metadata is invalid.');
        }
    }

    /** @return array{size_bytes: int, sha256: string} */
    public function publicEvidence(): array
    {
        return ['size_bytes' => $this->sizeBytes, 'sha256' => $this->sha256];
    }
}
