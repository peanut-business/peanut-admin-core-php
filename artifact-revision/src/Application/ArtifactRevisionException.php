<?php

declare(strict_types=1);

namespace PeanutAdmin\ArtifactRevision\Application;

use RuntimeException;

final class ArtifactRevisionException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message = 'The artifact revision request is invalid.'): self
    {
        return new self('ARTIFACT_REVISION_INVALID', 422, $message);
    }

    public static function notFound(): self
    {
        return new self('ARTIFACT_REVISION_NOT_FOUND', 404, 'The artifact revision is unavailable.');
    }

    public static function conflict(): self
    {
        return new self('ARTIFACT_REVISION_CONFLICT', 409, 'The artifact revision has changed.');
    }

    public static function integrityFailure(): self
    {
        return new self(
            'ARTIFACT_REVISION_INTEGRITY_FAILURE',
            500,
            'The artifact revision failed its integrity check.',
        );
    }

    public static function internal(): self
    {
        return new self(
            'ARTIFACT_REVISION_INTERNAL_ERROR',
            500,
            'The artifact revision operation could not be completed.',
        );
    }
}
