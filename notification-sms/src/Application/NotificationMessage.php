<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Application;

final readonly class NotificationMessage
{
    /** @param list<AttachmentReference> $attachments */
    public function __construct(
        public string $messageKey,
        public string $templateKey,
        public int $templateRevision,
        public string $subject,
        public string $body,
        public string $status,
        public int $revision,
        public string $createdAt,
        public ?string $readAt,
        public ?string $archivedAt,
        public array $attachments,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'message_key' => $this->messageKey,
            'template_key' => $this->templateKey,
            'template_revision' => $this->templateRevision,
            'subject' => $this->subject,
            'body' => $this->body,
            'status' => $this->status,
            'revision' => $this->revision,
            'created_at' => $this->createdAt,
            'read_at' => $this->readAt,
            'archived_at' => $this->archivedAt,
            'attachments' => array_map(static fn(AttachmentReference $attachment): array => [
                'file_key' => $attachment->fileKey,
                'original_name' => $attachment->originalName,
                'media_type' => $attachment->mediaType,
                'size_bytes' => $attachment->sizeBytes,
                'sha256' => $attachment->sha256,
            ], $this->attachments),
        ];
    }
}
