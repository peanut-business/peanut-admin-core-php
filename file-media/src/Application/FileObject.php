<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Application;

final readonly class FileObject
{
    public function __construct(
        public int $id,
        public string $fileKey,
        public int $tenantId,
        public string $storageProviderKey,
        public string $storageKey,
        public string $originalName,
        public string $mediaType,
        public int $sizeBytes,
        public string $sha256,
        public string $status,
        public int $createdByMemberId,
        public int $revision,
        public string $createdAt,
        public string $updatedAt,
        public ?string $archivedAt,
    ) {}

    public function etag(): string
    {
        return '"rev-' . $this->revision . '"';
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'file_key' => $this->fileKey,
            'original_name' => $this->originalName,
            'media_type' => $this->mediaType,
            'size_bytes' => $this->sizeBytes,
            'sha256' => $this->sha256,
            'status' => $this->status,
            'revision' => $this->revision,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'archived_at' => $this->archivedAt,
        ];
    }

    /** @return array<string, int|string> */
    public function auditMetadata(): array
    {
        return [
            'media_type' => $this->mediaType,
            'size_bytes' => $this->sizeBytes,
            'file_count' => 1,
            'revision' => $this->revision,
            'status' => $this->status,
        ];
    }
}
