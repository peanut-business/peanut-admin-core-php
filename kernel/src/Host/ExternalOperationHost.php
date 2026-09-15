<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

use Closure;
use InvalidArgumentException;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Idempotency\IdempotencyKey;
use PeanutAdmin\Kernel\Idempotency\IdempotencyRecord;
use PeanutAdmin\Kernel\Idempotency\IdempotencyService;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use think\facade\Db;
use Throwable;

final readonly class ExternalOperationHost
{
    public function __construct(
        private ExternalHostConfiguration $configuration,
        private TrustedContextAdapter $trustedContext,
        private ModuleAvailabilityAdapter $modules,
        private PermissionAdapter $permissions,
        private TypedTargetAdapter $targets,
        private IdempotencyService $idempotency,
        private AuditService $audit,
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
     * @param callable(AuthorizedExternalOperation, ExternalOperationRequest): ExternalOperationResult $handler
     * @param null|callable(ExternalOperationResult): void $outbox
     * @param null|callable(AuthorizedExternalOperation, ExternalOperationRequest): void $guard
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

            return $this->executeAtomic(
                $operation,
                $authorized->context,
                $request,
                static fn(): ExternalOperationResult => $handler($authorized, $request),
                $outbox,
                $guard === null
                    ? null
                    : static function () use ($guard, $authorized, $request): void {
                        $guard($authorized, $request);
                    },
            );
        } catch (Throwable $throwable) {
            return $this->problems->respond($throwable, $request->requestId);
        }
    }

    /**
     * @param callable(): ExternalOperationResult $domain
     * @param null|callable(ExternalOperationResult): void $outbox
     * @param null|callable(): void $guard
     */
    private function executeAtomic(
        ExternalOperationDefinition $operation,
        TenantContext|PlatformContext $context,
        ExternalOperationRequest $request,
        callable $domain,
        ?callable $outbox,
        ?callable $guard,
    ): ExternalOperationResponse {
        return Db::transaction(function () use ($operation, $context, $request, $domain, $outbox, $guard): ExternalOperationResponse {
            if ($guard !== null) {
                $guard();
            }
            $record = $this->acquireIdempotency($operation, $context, $request);
            if ($record?->replayable()) {
                return new ExternalOperationResponse((int) $record->responseStatus, (array) $record->responseBody);
            }
            if ($record !== null && !$record->acquiredForExecution()) {
                throw new ApiException(
                    'IDEMPOTENCY_REQUEST_PROCESSING',
                    409,
                    'Another request with this Idempotency-Key is still processing.',
                );
            }

            $result = $domain();
            $this->appendAudit($context, $result);
            if ($outbox !== null) {
                $outbox($result);
            }
            if ($record !== null) {
                $this->completeIdempotency($context, $record, $result);
            }

            return $result->response();
        });
    }

    private function acquireIdempotency(
        ExternalOperationDefinition $operation,
        TenantContext|PlatformContext $context,
        ExternalOperationRequest $request,
    ): ?IdempotencyRecord {
        if (!$operation->idempotencyRequired && $request->idempotencyKey === null) {
            return null;
        }
        $key = IdempotencyKey::fromString($request->idempotencyKey);
        if ($context instanceof TenantContext) {
            return $this->idempotency->beginTenant(
                $this->tenantScope($context),
                $context->memberId,
                $operation->operationId,
                $key,
                $request->requestHash(),
                $request->idempotencyExpiresAt,
                $request->comparisonTime,
            );
        }

        return $this->idempotency->beginPlatform(
            $context->operatorId,
            $operation->operationId,
            $key,
            $request->requestHash(),
            $request->idempotencyExpiresAt,
            $request->comparisonTime,
        );
    }

    private function appendAudit(
        TenantContext|PlatformContext $context,
        ExternalOperationResult $result,
    ): void {
        if ($context instanceof TenantContext) {
            $this->audit->tenantMember(
                $context,
                $result->auditEventType,
                $result->auditAction,
                $result->resourceType,
                $result->resourceId,
                metadata: $result->auditMetadata,
            );

            return;
        }
        $this->audit->platform(
            $context->operatorId,
            $context->accountId,
            $context->requestId,
            $result->auditEventType,
            $result->auditAction,
            $result->auditMetadata,
        );
    }

    private function completeIdempotency(
        TenantContext|PlatformContext $context,
        IdempotencyRecord $record,
        ExternalOperationResult $result,
    ): void {
        $responseBody = $result->idempotencyBody ?? $result->body;
        if ($context instanceof TenantContext) {
            $this->idempotency->completeTenant(
                $this->tenantScope($context),
                $record->id,
                $result->status,
                $responseBody,
                $result->resourceType,
                $result->resourceId,
            );

            return;
        }
        $this->idempotency->completePlatform(
            $record->id,
            $result->status,
            $responseBody,
            $result->resourceType,
            $result->resourceId,
        );
    }

    private function tenantScope(TenantContext $context): TenantScope
    {
        return TenantScope::fromTrustedContext($context->tenantId, 'external-operation-host');
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
