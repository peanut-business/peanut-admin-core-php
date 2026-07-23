<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Application;

final readonly class AttachmentReference
{
    public function __construct(
        public string $fileKey,
        public string $originalName,
        public string $mediaType,
        public int $sizeBytes,
        public string $sha256,
    ) {
        if (preg_match('/^file_[0-9a-f]{32}$/D', $fileKey) !== 1
            || $originalName === '' || mb_strlen($originalName) > 255 || str_contains($originalName, "\0")
            || preg_match('/^[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*$/D', $mediaType) !== 1
            || $sizeBytes < 0
            || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1
        ) {
            throw NotificationException::attachmentUnavailable();
        }
    }
}
