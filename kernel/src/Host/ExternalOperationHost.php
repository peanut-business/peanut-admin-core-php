<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

use Closure;
use InvalidArgumentException;
use PDO;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use Throwable;

final readonly class ExternalOperationHost
{
    public function __construct(
        private ExternalHostConfiguration $configuration,
        private TrustedContextAdapter $trustedContext,
        private ModuleAvailabilityAdapter $modules,
        private PermissionAdapter $permissions,
        private TypedTargetAdapter $targets,
        private AtomicOperationAdapter $atomic,
        private ProblemDetailsAdapter $problems,
    ) {}

    /** @param callable(AuthorizedExternalOperation, ExternalOperationRequest): ExternalOperationResponse $handler */
    public function read(
        ExternalOperationDefinition $operation,
        ExternalOperationRequest $request,
        callable $handler,
    ): ExternalOperationResponse {
        try {
            if ($operation->atomicCommand) {
                throw new InvalidArgumentException('Read handling requires a non-atomic operation.');
            }
            return $handler($this->authorize($operation, $request), $request);
        } catch (Throwable $throwable) {
            return $this->problems->respond($throwable, $request->requestId);
        }
    }

    /**
     * @param callable(AuthorizedExternalOperation, ExternalOperationRequest, PDO): ExternalOperationResult $handler
     * @param null|callable(PDO, ExternalOperationResult): void $outbox
     * @param null|callable(AuthorizedExternalOperation, ExternalOperationRequest, PDO): void $guard
     */
    public function command(
        ExternalOperationDefinition $operation,
        ExternalOperationRequest $request,
        callable $handler,
        ?callable $outbox = null,
        ?callable $guard = null,
    ): ExternalOperationResponse {
        try {
            if (!$operation->atomicCommand) {
                throw new InvalidArgumentException('Command handling requires an atomic operation.');
            }
            $authorized = $this->authorize($operation, $request);

            return $this->atomic->execute(
                $operation,
                $authorized->context,
                $request,
                static fn(PDO $pdo): ExternalOperationResult => $handler($authorized, $request, $pdo),
                $outbox,
                $guard === null
                    ? null
                    : static function (PDO $pdo) use ($guard, $authorized, $request): void {
                        $guard($authorized, $request, $pdo);
                    },
            );
        } catch (Throwable $throwable) {
            return $this->problems->respond($throwable, $request->requestId);
        }
    }

    private function authorize(
        ExternalOperationDefinition $operation,
        ExternalOperationRequest $request,
    ): AuthorizedExternalOperation {
        $this->configuration->assertOperation($operation);
        if (!$operation->matches($request->method, $request->path)) {
            throw new ApiException('OPERATION_NOT_FOUND', 404, 'The requested operation is unavailable.');
        }
        $context = $this->trustedContext->require($operation, $request);
        $this->modules->assertAvailable($operation, $context, $request->comparisonTime);
        $this->permissions->authorize($operation, $context);
        if ($context instanceof TenantContext) {
            $targetAuthorization = $this->targets->authorize($operation, $context, $request->typedTargets);

            return $this->authorized(
                $context,
                $operation,
                $targetAuthorization->queryConstraint,
                $targetAuthorization->targets,
            );
        }
        if ($operation->dataAuthorization !== 'none' || $request->typedTargets !== []) {
            throw new ApiException('VALIDATION_FAILED', 422, 'Platform operations cannot use typed targets.');
        }

        return $this->authorized($context, $operation);
    }

    /** @param list<RequestedTargetSet> $targets */
    private function authorized(
        TenantContext|PlatformContext $context,
        ExternalOperationDefinition $operation,
        ?object $queryConstraint = null,
        array $targets = [],
    ): AuthorizedExternalOperation {
        $issuer = Closure::bind(
            static fn() => new AuthorizedExternalOperation(
                $context,
                $operation,
                $queryConstraint,
                $targets,
            ),
            null,
            AuthorizedExternalOperation::class,
        );
        return $issuer();
    }
}
