<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Api\TypedTargetInput;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\DataPermissionAdapter;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;

final readonly class TypedTargetAuthorization
{
    /** @param list<RequestedTargetSet> $targets */
    public function __construct(
        public ?object $queryConstraint,
        public array $targets,
    ) {}
}

final readonly class TypedTargetAdapter
{
    public function __construct(private DataPermissionAdapter $authorization) {}

    /** @param list<array<string, mixed>> $targets */
    public function authorize(
        ExternalOperationDefinition $operation,
        TenantContext $context,
        array $targets,
    ): TypedTargetAuthorization {
        if ($operation->dataAuthorization === 'none') {
            if ($targets !== []) {
                throw $this->invalid('This operation does not accept typed targets.');
            }
            return new TypedTargetAuthorization(null, []);
        }

        $input = match ($operation->targetCardinality) {
            'one_required' => count($targets) === 1
                ? TypedTargetInput::one($targets[0])
                : throw $this->invalid('Exactly one typed target is required.'),
            'zero_or_one' => match (count($targets)) {
                0 => null,
                1 => TypedTargetInput::one($targets[0]),
                default => throw $this->invalid('At most one typed target is allowed.'),
            },
            'many_readable' => TypedTargetInput::many($targets),
            default => throw $this->invalid('Typed-target cardinality is not supported.'),
        };
        $requested = $input === null ? [] : $input->sets;
        if ($operation->dataAuthorization === 'query') {
            return new TypedTargetAuthorization(
                $this->authorization->queryConstraint(
                    $context,
                    (string) $operation->resourceKey,
                    $operation->operationId,
                    $requested,
                ),
                $requested,
            );
        }

        $this->authorization->assertTargetsAllowed(
            $context,
            (string) $operation->resourceKey,
            $operation->operationId,
            $requested,
        );

        return new TypedTargetAuthorization(null, $requested);
    }

    private function invalid(string $message): ApiException
    {
        return new ApiException('VALIDATION_FAILED', 422, 'One or more fields are invalid.', [[
            'pointer' => '/targets',
            'code' => 'AUTHZ_TARGET_TYPE_MISMATCH',
            'message' => $message,
        ]]);
    }
}
