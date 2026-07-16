<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Api;

use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use Throwable;

final readonly class TypedTargetInput
{
    /** @param list<RequestedTargetSet> $sets */
    private function __construct(public array $sets) {}

    /** @param array<string, mixed> $target */
    public static function one(array $target): self
    {
        if (array_key_exists('target_ids', $target)) {
            throw self::invalid('/target/target_ids', 'TARGET_ONE_REQUIRED', 'A write target requires one target_id.');
        }
        $resourceKey = $target['target_resource_key'] ?? null;
        $targetId = $target['target_id'] ?? null;
        $targetRole = $target['target_role'] ?? 'primary';
        if (!is_string($resourceKey) || !is_string($targetId) || !is_string($targetRole) || $targetRole === '') {
            throw self::invalid('/target', 'TARGET_ONE_REQUIRED', 'A typed target is required.');
        }

        return new self([new RequestedTargetSet($resourceKey, [$targetId], $targetRole)]);
    }

    /** @param list<array<string, mixed>> $targets */
    public static function many(array $targets): self
    {
        $sets = [];
        $seen = [];
        foreach ($targets as $index => $target) {
            $resourceKey = $target['target_resource_key'] ?? null;
            $targetIds = $target['target_ids'] ?? null;
            $targetRole = $target['target_role'] ?? 'primary';
            if (
                !is_string($resourceKey)
                || !is_array($targetIds)
                || $targetIds === []
                || !is_string($targetRole)
                || $targetRole === ''
            ) {
                throw self::invalid("/targets/{$index}", 'AUTHZ_TARGET_TYPE_MISMATCH', 'Each target set requires one type and IDs.');
            }
            try {
                $relationKey = $targetRole . ':' . $resourceKey;
                if (isset($seen[$relationKey])) {
                    throw self::invalid('/targets', 'AUTHZ_TARGET_TYPE_MISMATCH', 'A target role and type cannot be repeated.');
                }
                $seen[$relationKey] = true;
                $sets[] = new RequestedTargetSet(
                    $resourceKey,
                    array_values(array_map('strval', $targetIds)),
                    $targetRole,
                );
            } catch (Throwable) {
                throw self::invalid("/targets/{$index}", 'AUTHZ_TARGET_TYPE_MISMATCH', 'Target set is invalid.');
            }
        }
        return new self($sets);
    }

    private static function invalid(string $pointer, string $code, string $message): ApiException
    {
        return new ApiException('VALIDATION_FAILED', 422, 'One or more fields are invalid.', [[
            'pointer' => $pointer,
            'code' => $code,
            'message' => $message,
        ]]);
    }
}
