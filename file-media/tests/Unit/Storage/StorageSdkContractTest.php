<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Tests\Unit\Storage;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PeanutAdmin\FileMedia\Storage\Driver\QcloudStorageDriver;
use PeanutAdmin\FileMedia\Storage\Driver\QiniuStorageDriver;
use PeanutAdmin\FileMedia\Storage\StorageCapabilityUnsupported;
use PeanutAdmin\FileMedia\Storage\StorageHttpTransport;
use PeanutAdmin\FileMedia\Storage\StorageVersionCursor;
use PHPUnit\Framework\TestCase;
use Qcloud\Cos\Client;
use Qcloud\Cos\Exception\ServiceResponseException;
use Qiniu\Auth;

/** Host-installed SDK request serialization/deserialization, with no network access. */
final class StorageSdkContractTest extends TestCase
{
    public function testCosMetadataPrivateAclAndExactVersionDeleteThroughInstalledSdk(): void
    {
        $history = [];
        $driver = $this->cos([
            $this->head('v1'),
            new Response(200, ['x-cos-request-id' => 'acl-ack']),
            new Response(204, ['x-cos-request-id' => 'delete-ack', 'x-cos-version-id' => 'v1']),
        ], $history);
        $observation = $driver->inspect('exact/object.txt', 'v1');
        self::assertSame(4, $observation->sizeBytes);
        self::assertSame('text/plain', $observation->mediaType);
        self::assertFalse($observation->mediaTypeVerified);
        self::assertSame('"opaque-etag"', $observation->etag);
        self::assertSame('v1', $observation->versionId);
        self::assertSame('head-ack', $observation->requestId);
        self::assertObjectNotHasProperty('cdnDelivery', $driver->capabilities());
        self::assertSame('acl-ack', $driver->restrictToPrivate('exact/object.txt')->requestId);
        self::assertSame('v1', $driver->delete('exact/object.txt', 'v1')->versionId);
        self::assertCount(3, $history);
        parse_str($history[0]['request']->getUri()->getQuery(), $headQuery);
        parse_str($history[2]['request']->getUri()->getQuery(), $deleteQuery);
        self::assertSame('v1', $headQuery['versionId']);
        self::assertSame('v1', $deleteQuery['versionId']);
        self::assertSame('private', $history[1]['request']->getHeaderLine('x-cos-acl'));
        self::assertStringContainsString('/exact/object.txt', $history[2]['request']->getUri()->getPath());
        self::assertSame('DELETE', $history[2]['request']->getMethod());
    }

    public function testCosHistoricalAclIsRejectedBeforeSdkCanIgnoreVersionParameter(): void
    {
        $history = [];
        $driver = $this->cos([], $history);
        try {
            $driver->restrictToPrivate('exact/object.txt', 'old');
            self::fail('The locked SDK cannot target a historical ACL.');
        } catch (StorageCapabilityUnsupported $exception) {
            self::assertSame('historical-object-acl-restriction', $exception->capability);
            self::assertSame([], $history);
        }
    }

    public function testCosVersionPageFiltersNeighboursAndBindsContinuationToExactKey(): void
    {
        $history = [];
        $xml = '<ListVersionsResult><IsTruncated>true</IsTruncated>'
            . '<NextKeyMarker>exact/object.txt</NextKeyMarker><NextVersionIdMarker>v2</NextVersionIdMarker>'
            . '<Version><Key>exact/object.txt</Key><VersionId>v1</VersionId><IsLatest>false</IsLatest><ETag>etag</ETag><Size>4</Size></Version>'
            . '<Version><Key>exact/object.txt-neighbour</Key><VersionId>other</VersionId><IsLatest>true</IsLatest><ETag>other</ETag><Size>5</Size></Version>'
            . '<DeleteMarker><Key>exact/object.txt</Key><VersionId>v2</VersionId><IsLatest>true</IsLatest></DeleteMarker>'
            . '</ListVersionsResult>';
        $driver = $this->cos([new Response(200, ['x-cos-request-id' => 'list-ack', 'Content-Type' => 'application/xml'], $xml)], $history);
        $page = $driver->listVersions('exact/object.txt', new StorageVersionCursor('exact/object.txt', 'v0'), 3);
        self::assertCount(2, $page->versions);
        self::assertSame(['v1', 'v2'], array_map(fn($version) => $version->versionId, $page->versions));
        self::assertTrue($page->versions[1]->deleteMarker);
        self::assertNull($page->versions[1]->sizeBytes);
        self::assertSame('v2', $page->nextCursor?->versionIdMarker);
        parse_str($history[0]['request']->getUri()->getQuery(), $query);
        self::assertSame('exact/object.txt', $query['prefix']);
        self::assertSame('exact/object.txt', $query['key-marker']);
        self::assertSame('v0', $query['version-id-marker']);
        self::assertSame('3', $query['max-keys']);
        try {
            $driver->listVersions('exact/object.txt', new StorageVersionCursor('other/file.txt', 'v2'), 3);
            self::fail('Another object continuation must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertCount(1, $history);
        }
    }

    public function testCosReadsTheExactHistoricalBytesThroughSdkDownload(): void
    {
        $history = [];
        $driver = $this->cos([
            $this->head('old'),
            new Response(200, ['x-cos-request-id' => 'read-ack', 'Content-Length' => '4', 'Content-Type' => 'text/plain', 'x-cos-version-id' => 'old'], 'keep'),
        ], $history);
        $path = tempnam(sys_get_temp_dir(), 'peanut-sdk-read-');
        try {
            $driver->downloadTo('exact/object.txt', $path, 'old');
            self::assertSame('keep', file_get_contents($path));
            self::assertCount(2, $history);
            foreach ($history as $call) {
                parse_str($call['request']->getUri()->getQuery(), $query);
                self::assertSame('old', $query['versionId']);
            }
        } finally {
            unlink($path);
        }
    }

    public function testCosOnlyDefiniteMissingObjectsBecomeAbsentAndUnknownDeleteIsRejected(): void
    {
        $history = [];
        $driver = $this->cos([
            new Response(404, ['x-cos-request-id' => 'missing'], '<Error><Code>NoSuchKey</Code><Message>The specified key does not exist.</Message><RequestId>missing</RequestId></Error>'),
            new Response(403, ['x-cos-request-id' => 'denied'], '<Error><Code>AccessDenied</Code><Message>Access Denied.</Message><RequestId>denied</RequestId></Error>'),
            new Response(204),
        ], $history);
        self::assertFalse($driver->inspect('exact/object.txt')->exists);
        try {
            $driver->inspect('exact/object.txt');
            self::fail('Access denial is not absence.');
        } catch (ServiceResponseException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        $this->expectException(\UnexpectedValueException::class);
        $driver->delete('exact/object.txt');
    }

    public function testCosDeletionMarkerReceiptDoesNotClaimByteRemoval(): void
    {
        $history = [];
        $driver = $this->cos([new Response(204, [
            'x-cos-request-id' => 'marker-ack', 'x-cos-version-id' => 'marker', 'x-cos-delete-marker' => 'true',
        ])], $history);
        $receipt = $driver->delete('exact/object.txt');
        self::assertTrue($receipt->deleteMarker);
        self::assertSame('acknowledged', $receipt->outcome);
        self::assertSame('marker', $receipt->versionId);
    }

    public function testQiniuStatReadAndDeleteUseInstalledAuthAndPreserveProviderEvidence(): void
    {
        $this->qiniuAvailable();
        $transport = $this->transport([
            ['status' => 200, 'body' => '{"fsize":4,"mimeType":"text/plain","hash":"qiniu-opaque-hash"}', 'headers' => ['X-Reqid' => 'stat-ack']],
            ['status' => 200, 'body' => 'keep'],
            ['status' => 200, 'body' => '', 'headers' => ['X-Reqid' => 'delete-ack']],
            ['status' => 612, 'body' => '{"error":"no such file or directory"}', 'headers' => ['X-Reqid' => 'missing-ack']],
        ]);
        $driver = new QiniuStorageDriver(new Auth('contract-ak', 'contract-sk'), 'contract-bucket', '', 'https://origin.example.test', $transport);
        $observation = $driver->inspect('exact/object.txt');
        self::assertSame(4, $observation->sizeBytes);
        self::assertSame('qiniu-opaque-hash', $observation->etag);
        self::assertFalse($observation->mediaTypeVerified);
        self::assertSame('stat-ack', $observation->requestId);
        self::assertStringContainsString('/stat/' . \Qiniu\entry('contract-bucket', 'exact/object.txt'), $transport->requests[0]['url']);
        self::assertStringStartsWith('QBox ', $transport->requests[0]['headers']['Authorization']);
        $path = tempnam(sys_get_temp_dir(), 'peanut-qiniu-read-');
        try {
            $driver->downloadTo('exact/object.txt', $path);
            self::assertSame('keep', file_get_contents($path));
            self::assertSame('delete-ack', $driver->delete('exact/object.txt')->requestId);
            self::assertFalse($driver->inspect('exact/object.txt')->exists);
            self::assertStringContainsString('/delete/' . \Qiniu\entry('contract-bucket', 'exact/object.txt'), $transport->requests[2]['url']);
            self::assertFalse($driver->capabilities()->objectAclRestriction);
            self::assertFalse($driver->capabilities()->objectVersionListing);
            foreach ([
                fn() => $driver->restrictToPrivate('exact/object.txt'),
                fn() => $driver->listVersions('exact/object.txt'),
                fn() => $driver->inspect('exact/object.txt', 'old'),
                fn() => $driver->delete('exact/object.txt', 'old'),
                fn() => $driver->downloadTo('exact/object.txt', $path, 'old'),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('Kodo cannot silently ignore a version or invent an object ACL.');
                } catch (StorageCapabilityUnsupported) {
                    self::assertCount(4, $transport->requests);
                }
            }
        } finally {
            unlink($path);
        }
    }

    public function testQiniuRejectsMalformedMetadataMissingReceiptAndAuthorizationFailure(): void
    {
        $this->qiniuAvailable();
        $transport = $this->transport([
            ['status' => 200, 'body' => '{"fsize":4}', 'headers' => ['X-Reqid' => 'malformed']],
            ['status' => 200, 'body' => ''],
            ['status' => 401, 'body' => '{"error":"unauthorized"}', 'headers' => ['X-Reqid' => 'denied']],
        ]);
        $driver = new QiniuStorageDriver(new Auth('contract-ak', 'contract-sk'), 'contract-bucket', '', '', $transport);
        foreach ([fn() => $driver->inspect('exact/object.txt'), fn() => $driver->delete('exact/object.txt'), fn() => $driver->inspect('exact/object.txt')] as $operation) {
            try {
                $operation();
                self::fail('Unknown provider facts cannot become a successful observation.');
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
        self::assertCount(3, $transport->requests);
    }

    /** @param list<Response> $responses @param array<mixed> $history */
    private function cos(array $responses, array &$history): QcloudStorageDriver
    {
        if (!class_exists(Client::class) || !method_exists(Client::class, 'getApi')) {
            self::markTestSkipped('Host-installed COS SDK is required for its transport contract.');
        }
        $client = new Client(['region' => 'ap-guangzhou', 'scheme' => 'https', 'retry' => 0, 'credentials' => ['secretId' => 'contract-id', 'secretKey' => 'contract-key']]);
        $stack = $client->httpClient->getConfig('handler');
        $stack->setHandler(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        return new QcloudStorageDriver($client, 'contract-bucket-1250000000');
    }

    private function head(string $version): Response
    {
        return new Response(200, [
            'x-cos-request-id' => 'head-ack', 'Content-Length' => '4', 'Content-Type' => 'text/plain; charset=utf-8',
            'ETag' => '"opaque-etag"', 'x-cos-version-id' => $version,
            'Last-Modified' => 'Mon, 12 Oct 2026 00:00:00 GMT', 'x-cos-hash-crc64ecma' => '0',
        ]);
    }

    private function qiniuAvailable(): void
    {
        if (!class_exists(Auth::class) || !method_exists(Auth::class, 'signRequest')) {
            self::markTestSkipped('Host-installed Qiniu SDK is required for its authentication contract.');
        }
    }

    /** @param list<array{status: int, body: string, headers?: array<string, string>}> $responses */
    private function transport(array $responses): StorageHttpTransport
    {
        return new class($responses) implements StorageHttpTransport {
            public array $requests = [];
            public function __construct(private array $responses) {}
            public function request(array $request): array
            {
                $this->requests[] = $request;
                $response = array_shift($this->responses) ?? throw new \RuntimeException('Unexpected provider request.');
                if (isset($request['sink'])) {
                    file_put_contents($request['sink'], $response['body']);
                }
                return $response;
            }
        };
    }
}
