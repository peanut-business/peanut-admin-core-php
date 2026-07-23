<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Governance;

final class RevisionPrecondition
{
    public static function require(string $ifMatch, int $currentRevision): int
    {
        if ($ifMatch === '') {
            throw new GovernanceException('PRECONDITION_REQUIRED', 'If-Match is required.');
        }
        if (preg_match('/^"rev-([1-9][0-9]*)"$/D', $ifMatch, $matches) !== 1) {
            throw new GovernanceException('PRECONDITION_INVALID', 'If-Match must contain one strong revision ETag.');
        }
        $revision = (int) $matches[1];
        if ((string) $revision !== $matches[1] || $revision !== $currentRevision) {
            throw new GovernanceException('REVISION_MISMATCH', 'The governed resource revision has changed.');
        }

        return $revision;
    }

    private function __construct() {}
}
