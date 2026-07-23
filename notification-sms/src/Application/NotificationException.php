<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Application;

use RuntimeException;

final class NotificationException extends RuntimeException
{
    private function __construct(public readonly string $problemCode)
    {
        parent::__construct($problemCode);
    }

    public static function invalid(string $code = 'NOTIFICATION_INPUT_INVALID'): self
    {
        return new self($code);
    }

    public static function denied(): self
    {
        return new self('NOTIFICATION_PERMISSION_DENIED');
    }

    public static function notFound(): self
    {
        return new self('NOTIFICATION_NOT_FOUND');
    }

    public static function conflict(): self
    {
        return new self('NOTIFICATION_STATE_CONFLICT');
    }

    public static function recipientUnavailable(): self
    {
        return new self('NOTIFICATION_RECIPIENT_UNAVAILABLE');
    }

    public static function attachmentUnavailable(): self
    {
        return new self('NOTIFICATION_ATTACHMENT_UNAVAILABLE');
    }
}
