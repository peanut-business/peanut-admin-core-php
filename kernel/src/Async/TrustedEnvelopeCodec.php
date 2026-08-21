<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Async;

use JsonException;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;

final readonly class TrustedEnvelopeCodec
{
    public function __construct(private string $signingKey)
    {
        if (strlen($signingKey) < 32) {
            throw new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
        }
    }

    public function issue(
        AuthorizedOperationContext $context,
        string $operationId,
        string $traceId,
    ): string {
        $payload = [
            'version' => 1,
            'tenant_id' => $context->tenantContext->tenantId,
            'account_id' => $context->tenantContext->accountId,
            'member_id' => $context->tenantContext->memberId,
            'resource_key' => $context->resourceKey,
            'operation' => $context->operation,
            'requested_targets' => array_map(
                static fn(RequestedTargetSet $set): array => $set->toArray(),
                $context->targets,
            ),
            'operation_id' => $operationId,
            'trace_id' => $traceId,
        ];
        $canonical = $this->json($payload);

        return $this->json([
            'payload' => $payload,
            'signature' => hash_hmac('sha256', $canonical, $this->signingKey),
        ]);
    }

    public function verify(string $encoded): VerifiedJobEnvelope
    {
        try {
            $document = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
        }
        if (!is_array($document)) {
            throw new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
        }
        $payload = $document['payload'] ?? null;
        $signature = $document['signature'] ?? null;
        if (!is_array($payload) || !is_string($signature)) {
            throw new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
        }
        $expected = hash_hmac('sha256', $this->json($payload), $this->signingKey);
        if (!hash_equals($expected, $signature)) {
            throw new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
        }

        return new VerifiedJobEnvelope(
            $this->positiveInt($payload, 'tenant_id'),
            $this->positiveInt($payload, 'account_id'),
            $this->positiveInt($payload, 'member_id'),
            $this->string($payload, 'resource_key'),
            $this->string($payload, 'operation'),
            $this->targetSets($payload['requested_targets'] ?? null),
            $this->string($payload, 'operation_id'),
            $this->string($payload, 'trace_id'),
        );
    }

    /** @param array<string|int, mixed> $payload */
    private function positiveInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (!is_int($value) || $value <= 0) {
            throw new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
        }

        return $value;
    }

    /** @param array<string|int, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
        }

        return $value;
    }

    /** @return list<RequestedTargetSet> */
    private function targetSets(mixed $value): array
    {
        if (!is_array($value)) {
            throw new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
        }

        $sets = [];
        foreach ($value as $rawSet) {
            if (!is_array($rawSet)) {
                throw new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
            }
            $resourceKey = $rawSet['target_resource_key'] ?? null;
            $targetRole = $rawSet['target_role'] ?? 'primary';
            $targetIds = $rawSet['target_ids'] ?? null;
            if (
                !is_string($resourceKey)
                || !is_string($targetRole)
                || $targetRole === ''
                || !is_array($targetIds)
                || $targetIds === []
            ) {
                throw new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
            }
            $stringIds = [];
            foreach ($targetIds as $targetId) {
                if (!is_string($targetId) || $targetId === '') {
                    throw new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
                }
                $stringIds[] = $targetId;
            }
            $sets[] = new RequestedTargetSet($resourceKey, $stringIds, $targetRole);
        }

        return $sets;
    }

    /** @throws JsonException */
    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
