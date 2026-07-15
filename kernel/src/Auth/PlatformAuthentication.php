<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use PeanutAdmin\Kernel\Context\PlatformContext;

final readonly class PlatformAuthentication
{
    public function __construct(
        public PlatformTokenPair $tokens,
        public PlatformContext $context,
    ) {}

    /** @return array<string, mixed> */
    public function responseData(): array
    {
        return [
            'state' => 'authenticated',
            'access_token' => $this->tokens->access->expose(),
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'context' => [
                'audience' => 'platform',
                'account_id' => (string) $this->context->accountId,
                'platform_operator_id' => (string) $this->context->operatorId,
            ],
        ];
    }
}
