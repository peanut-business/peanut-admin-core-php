<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

use InvalidArgumentException;
use PDO;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Idempotency\IdempotencyKey;
use PeanutAdmin\Kernel\Idempotency\IdempotencyRecord;
use PeanutAdmin\Kernel\Idempotency\PdoIdempotencyRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoAuditRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoTransactionManager;

final readonly class AtomicOperationAdapter
{
    private PdoTransactionManager $transactions;
    private PdoIdempotencyRepository $idempotency;
    private PdoAuditRepository $audit;

    public function __construct(private PDO $pdo)
    {
        $this->transactions = new PdoTransactionManager($pdo);
        $this->idempotency = new PdoIdempotencyRepository($pdo);
        $this->audit = new PdoAuditRepository($pdo);
    }

    /**
     * @param callable(PDO): ExternalOperationResult $domain
     * @param null|callable(PDO, ExternalOperationResult): void $outbox
     * @param null|callable(PDO): void $guard
     */
    public function execute(
        ExternalOperationDefinition $operation,
        TenantContext|PlatformContext $context,
        ExternalOperationRequest $request,
        callable $domain,
        ?callable $outbox = null,
        ?callable $guard = null,
    ): ExternalOperationResponse {
        if (!$operation->atomicCommand) {
            throw new InvalidArgumentException('Atomic adapter requires an atomic command definition.');
        }

        return $this->transactions->run(function () use ($operation, $context, $request, $domain, $outbox, $guard): ExternalOperationResponse {
            if ($guard !== null) {
                $guard($this->pdo);
            }
            $record = $this->acquire($operation, $context, $request);
            if ($record?->replayable()) {
                return new ExternalOperationResponse(
                    (int) $record->responseStatus,
                    (array) $record->responseBody,
                );
            }
            if ($record !== null && !$record->acquiredForExecution()) {
                throw new ApiException(
                    'IDEMPOTENCY_REQUEST_PROCESSING',
                    409,
                    'Another request with this Idempotency-Key is still processing.',
                );
            }

            $result = $domain($this->pdo);
            $this->appendAudit($context, $result);
            if ($outbox !== null) {
                $outbox($this->pdo, $result);
            }
            if ($record !== null) {
                $this->complete($context, $record, $result);
            }

            return $result->response();
        });
    }

    private function acquire(
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
                $context->tenantId,
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
            $this->audit->appendTenantMember(
                $context,
                $result->auditEventType,
                $result->auditAction,
                $result->resourceType,
                $result->resourceId,
                metadata: $result->auditMetadata,
            );
            return;
        }
        $this->audit->appendPlatform(
            $result->auditEventType,
            $result->auditAction,
            $context->requestId,
            $context->operatorId,
            $context->accountId,
            $result->auditMetadata,
        );
    }

    private function complete(
        TenantContext|PlatformContext $context,
        IdempotencyRecord $record,
        ExternalOperationResult $result,
    ): void {
        if ($context instanceof TenantContext) {
            $this->idempotency->completeTenant(
                $record->id,
                $result->status,
                $result->idempotencyBody ?? $result->body,
                $result->resourceType,
                $result->resourceId,
            );
            return;
        }
        $this->idempotency->completePlatform(
            $record->id,
            $result->status,
            $result->idempotencyBody ?? $result->body,
            $result->resourceType,
            $result->resourceId,
        );
    }
}
