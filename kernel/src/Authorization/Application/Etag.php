<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Application;

final class Etag
{
    private function __construct() {}

    public static function format(int $revision): string
    {
        return '"rev-' . $revision . '"';
    }

    public static function parse(?string $etag): int
    {
        if ($etag === null || $etag === '') {
            throw AdminAccessException::preconditionRequired();
        }
        if (preg_match('/^"rev-([1-9][0-9]*)"$/', $etag, $matches) !== 1) {
            throw AdminAccessException::invalid('ETAG_INVALID', 'If-Match must use the "rev-N" format.');
        }

        return (int) $matches[1];
    }
}
