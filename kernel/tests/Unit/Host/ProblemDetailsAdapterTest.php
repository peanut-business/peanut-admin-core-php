<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Host;

use PeanutAdmin\Kernel\Api\RequestId;
use PeanutAdmin\Kernel\Authorization\AuthorizationException;
use PeanutAdmin\Kernel\Host\ProblemDetailsAdapter;
use PeanutAdmin\Kernel\Module\ModuleException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProblemDetailsAdapterTest extends TestCase
{
    public function testKnownFailuresMapToStableNonEnumeratingProblems(): void
    {
        $adapter = new ProblemDetailsAdapter();
        $requestId = RequestId::fromHeader('req_fixture_0001');

        $denied = $adapter->respond(new AuthorizationException(), $requestId);
        self::assertSame(403, $denied->status);
        self::assertSame('AUTHZ_PERMISSION_DENIED', $denied->body['code']);
        self::assertSame('application/problem+json', $denied->contentType);

        $module = $adapter->respond(
            new ModuleException('MODULE_TENANT_DISABLED', 'fixture.record is disabled for tenant 99'),
            $requestId,
        );
        self::assertSame(404, $module->status);
        self::assertSame('MODULE_UNAVAILABLE', $module->body['code']);
        self::assertStringNotContainsString('fixture.record', (string) $module->body['detail']);
        self::assertSame('req_fixture_0001', $module->body['request_id']);
    }

    public function testUnknownFailureDoesNotExposeInternalDetail(): void
    {
        $response = (new ProblemDetailsAdapter())->respond(
            new RuntimeException('SQLSTATE secret /private/path ProviderClass'),
            RequestId::fromHeader('req_fixture_0002'),
        );

        self::assertSame(500, $response->status);
        self::assertSame('INTERNAL_ERROR', $response->body['code']);
        self::assertSame('An internal error occurred.', $response->body['detail']);
        self::assertStringNotContainsString('SQLSTATE', json_encode($response->body, JSON_THROW_ON_ERROR));
    }
}
