<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

use InvalidArgumentException;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Api\ProblemDetails;
use PeanutAdmin\Kernel\Api\RequestId;
use PeanutAdmin\Kernel\Authorization\AuthorizationException;
use PeanutAdmin\Kernel\Module\ModuleException;
use Throwable;

final readonly class ProblemDetailsAdapter
{
    public function respond(Throwable $throwable, RequestId $requestId): ExternalOperationResponse
    {
        $exception = $this->apiException($throwable);
        $problem = ProblemDetails::fromException($exception, $requestId);

        return new ExternalOperationResponse(
            $problem->status,
            $problem->toArray(),
            $problem->contentType(),
        );
    }

    private function apiException(Throwable $throwable): ApiException
    {
        if ($throwable instanceof ApiException) {
            return $throwable;
        }
        if ($throwable instanceof AuthorizationException) {
            return new ApiException('AUTHZ_PERMISSION_DENIED', 403, 'Request is not authorized.');
        }
        if ($throwable instanceof ModuleException) {
            $status = $throwable->errorCode === 'MODULE_INSTALLATION_FAILED' ? 503 : 404;
            return new ApiException('MODULE_UNAVAILABLE', $status, 'The requested Module is unavailable.');
        }
        if ($throwable instanceof InvalidArgumentException) {
            return new ApiException('VALIDATION_FAILED', 422, 'One or more fields are invalid.');
        }

        $values = get_object_vars($throwable);
        $code = $values['errorCode'] ?? null;
        if (is_string($code) && str_starts_with($code, 'AUTHZ_')) {
            return new ApiException('AUTHZ_DATA_DENIED', 404, 'The requested resource is unavailable.');
        }

        return new ApiException('INTERNAL_ERROR', 500, 'An internal error occurred.');
    }
}
