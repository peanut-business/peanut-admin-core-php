<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Api;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class OpenApiHandlerContract
{
    public const array JSON_HEADERS = ['X-Request-Id', 'Cache-Control'];

    public const array VERSIONED_HEADERS = ['X-Request-Id', 'Cache-Control', 'ETag'];

    public const array CREATED_HEADERS = ['X-Request-Id', 'Cache-Control', 'ETag', 'Location'];

    public const array AUTHENTICATED_HEADERS = ['X-Request-Id', 'Cache-Control', 'Set-Cookie'];

    public const array SESSION_CLEARED_HEADERS = ['X-Request-Id', 'Cache-Control', 'Set-Cookie'];

    /** @param list<string> $headers */
    public function __construct(
        public int $successStatus = 200,
        public bool $hasJsonBody = true,
        public array $headers = self::JSON_HEADERS,
    ) {}
}
