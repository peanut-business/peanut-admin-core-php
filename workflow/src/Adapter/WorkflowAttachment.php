<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Adapter;

use PeanutAdmin\Workflow\Application\WorkflowException;

final readonly class WorkflowAttachment
{
    public function __construct(
        public string $fileKey,
        public string $name,
        public string $mediaType,
        public int $sizeBytes,
        public string $sha256,
    ) {
        if (preg_match('/^file_[0-9a-f]{32}$/D', $fileKey) !== 1
            || $name === ''
            || mb_strlen($name) > 255
            || str_contains($name, "\0")
            || $mediaType === ''
            || strlen($mediaType) > 127
            || preg_match('/^[\x21-\x7e]+$/D', $mediaType) !== 1
            || $sizeBytes < 0
            || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) {
            throw WorkflowException::attachmentUnavailable();
        }
    }

    /** @return array{file_key: string, name: string, media_type: string, size_bytes: int, sha256: string} */
    public function toArray(): array
    {
        return [
            'file_key' => $this->fileKey,
            'name' => $this->name,
            'media_type' => $this->mediaType,
            'size_bytes' => $this->sizeBytes,
            'sha256' => $this->sha256,
        ];
    }
}
