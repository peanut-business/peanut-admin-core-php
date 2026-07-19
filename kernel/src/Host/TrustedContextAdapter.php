<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;

final readonly class TrustedContextAdapter
{
    public function __construct(private ExternalHostConfiguration $configuration) {}

    public function require(
        ExternalOperationDefinition $operation,
        ExternalOperationRequest $request,
    ): TenantContext|PlatformContext {
        if ($request->context === null) {
            throw new ApiException('AUTHENTICATION_REQUIRED', 401, 'Authentication is required.');
        }
        $context = $request->context;
        if (
            ($operation->audience === 'tenant' && !$context instanceof TenantContext)
            || ($operation->audience === 'platform' && !$context instanceof PlatformContext)
        ) {
            throw new ApiException('AUDIENCE_MISMATCH', 403, 'The authenticated audience cannot use this operation.');
        }
        if (!$this->configuration->acceptsClientKey($context->clientKey)) {
            throw new ApiException('AUTHENTICATION_REQUIRED', 401, 'Authentication is required.');
        }
        if (!hash_equals($context->requestId, $request->requestId->value)) {
            throw new ApiException('REQUEST_CONTEXT_MISMATCH', 400, 'Trusted context does not match the request.');
        }

        return $context;
    }
}
