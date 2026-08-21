<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Api;

use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Api\FilterAllowlist;
use PeanutAdmin\Kernel\Api\ProblemDetails;
use PeanutAdmin\Kernel\Api\RequestId;
use PeanutAdmin\Kernel\Api\TypedTargetInput;
use PHPUnit\Framework\TestCase;

final class ApiContractTest extends TestCase
{
    public function testRequestIdAndProblemDetailsHaveStableSafeShape(): void
    {
        $requestId = RequestId::fromHeader('req_01KPEANUTADMIN');
        $problem = ProblemDetails::fromException(
            new ApiException('AUTHZ_DATA_DENIED', 404, 'The requested resource does not exist or is not accessible.'),
            $requestId,
        );

        self::assertSame('req_01KPEANUTADMIN', $requestId->value);
        self::assertSame('application/problem+json', $problem->contentType());
        self::assertSame('urn:request:req_01KPEANUTADMIN', $problem->toArray()['instance']);
        self::assertArrayNotHasKey('trace', $problem->toArray());
    }

    public function testTypedTargetInputsKeepTypesAndCardinalityExplicit(): void
    {
        $one = TypedTargetInput::one([
            'target_resource_key' => 'example.project',
            'target_id' => '9001',
            'target_role' => 'destination',
        ]);
        $many = TypedTargetInput::many([
            ['target_resource_key' => 'example.project', 'target_ids' => ['9001'], 'target_role' => 'source'],
            ['target_resource_key' => 'example.project', 'target_ids' => ['9002'], 'target_role' => 'destination'],
        ]);

        self::assertSame(['9001'], $one->sets[0]->targetIds);
        self::assertSame('destination', $one->sets[0]->targetRole);
        self::assertCount(2, $many->sets);
        self::assertSame(['source', 'destination'], array_column($many->sets, 'targetRole'));

        $this->expectException(ApiException::class);
        TypedTargetInput::one([
            'target_resource_key' => 'example.project',
            'target_ids' => ['9001', '9002'],
        ]);
    }

    public function testFilterAndSortAllowlistCannotExpandQuerySurface(): void
    {
        $allowlist = new FilterAllowlist(['status', 'department_id'], ['created_at', 'name'], ['department']);

        self::assertSame(['status' => 'active'], $allowlist->filters(['status' => 'active']));
        self::assertSame([['created_at', 'desc'], ['name', 'asc']], $allowlist->sort('-created_at,name'));

        $this->expectException(ApiException::class);
        $allowlist->filters(['raw_sql' => '1=1']);
    }
}
