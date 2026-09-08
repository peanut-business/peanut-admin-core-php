<?php

declare(strict_types=1);

namespace OSS {
    if (!class_exists(OssClient::class)) {
        class OssClient
        {
            public const OSS_HEADERS = 'headers';
            public const OSS_OBJECT_ACL = 'x-oss-object-acl';
            public const OSS_ACL_TYPE_PRIVATE = 'private';
            public const OSS_FILE_DOWNLOAD = 'file-download';

            /** @var list<array{method: string, arguments: array<mixed>}> */
            public array $calls = [];

            /** @param array<mixed> $options */
            public function uploadFile(string $bucket, string $key, string $path, array $options): void
            {
                $this->calls[] = ['method' => __FUNCTION__, 'arguments' => func_get_args()];
            }

            public function deleteObject(string $bucket, string $key): void
            {
                $this->calls[] = ['method' => __FUNCTION__, 'arguments' => func_get_args()];
            }

            /** @param array<mixed> $options */
            public function getObject(string $bucket, string $key, array $options): void
            {
                $this->calls[] = ['method' => __FUNCTION__, 'arguments' => func_get_args()];
            }
        }
    }
}

namespace Qcloud\Cos {
    if (!class_exists(Client::class)) {
        class Client
        {
            /** @var list<array{method: string, arguments: array<mixed>}> */
            public array $calls = [];

            /** @param array<string, mixed> $request */
            public function putObject(array $request): void
            {
                $request['BodyContents'] = stream_get_contents($request['Body']);
                $this->calls[] = ['method' => __FUNCTION__, 'arguments' => [$request]];
            }

            /** @param array<string, mixed> $request */
            public function deleteObject(array $request): void
            {
                $this->calls[] = ['method' => __FUNCTION__, 'arguments' => [$request]];
            }

            public function download(string $bucket, string $key, string $target): void
            {
                $this->calls[] = ['method' => __FUNCTION__, 'arguments' => func_get_args()];
            }
        }
    }
}

namespace Qiniu {
    if (!class_exists(Auth::class)) {
        class Auth
        {
            /** @var list<array{method: string, arguments: array<mixed>}> */
            public array $calls = [];

            public function uploadToken(string $bucket): string
            {
                $this->calls[] = ['method' => __FUNCTION__, 'arguments' => func_get_args()];

                return 'upload-token';
            }

            /** @return array<string, string> */
            public function authorization(string $url, ?string $body, string $contentType): array
            {
                $this->calls[] = ['method' => __FUNCTION__, 'arguments' => func_get_args()];

                return ['Authorization' => 'QBox signed'];
            }

            public function privateDownloadUrl(string $url, int $expires): string
            {
                $this->calls[] = ['method' => __FUNCTION__, 'arguments' => func_get_args()];

                return $url . '?signed=' . $expires;
            }
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\entry')) {
        function entry(string $bucket, string $key): string
        {
            return 'encoded-' . rawurlencode($bucket . ':' . $key);
        }
    }
}

namespace PeanutAdmin\FileMedia\Tests\Unit\Storage {
    use OSS\OssClient;
    use PeanutAdmin\FileMedia\Storage\Driver\AliyunStorageDriver;
    use PeanutAdmin\FileMedia\Storage\Driver\LocalStorageDriver;
    use PeanutAdmin\FileMedia\Storage\Driver\QcloudStorageDriver;
    use PeanutAdmin\FileMedia\Storage\Driver\QiniuStorageDriver;
    use PeanutAdmin\FileMedia\Storage\StorageHttpTransport;
    use PeanutAdmin\FileMedia\Storage\StorageObjectKey;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Qcloud\Cos\Client;
    use Qiniu\Auth;

    final class StorageDriverContractTest extends TestCase
    {
        /** @var list<string> */
        private array $temporaryDirectories = [];

        public static function invalidObjectKeys(): iterable
        {
            yield 'empty' => [''];
            yield 'only separators' => ['///'];
            yield 'parent segment' => ['tenant/../secret'];
            yield 'embedded traversal marker' => ['tenant/file..bak'];
            yield 'current segment' => ['tenant/./file'];
            yield 'empty segment' => ['tenant//file'];
            yield 'leading dot' => ['.hidden'];
            yield 'space' => ['tenant/file name'];
            yield 'control byte' => ["tenant/file\0name"];
            yield 'too long' => [str_repeat('a', 256)];
        }

        #[DataProvider('invalidObjectKeys')]
        public function testObjectKeyRejectsMalformedOrUnsafeInput(string $objectKey): void
        {
            $this->expectException(\InvalidArgumentException::class);
            StorageObjectKey::assert($objectKey);
        }

        public function testObjectKeyNormalizesSeparatorsAndAcceptsBoundaryLengths(): void
        {
            self::assertSame('a', StorageObjectKey::assert('a'));
            self::assertSame('tenant/path/file-1.txt', StorageObjectKey::assert('/tenant\\path/file-1.txt/'));
            self::assertSame(str_repeat('a', 255), StorageObjectKey::assert(str_repeat('a', 255)));
        }

        public function testLocalDriverStoresDownloadsDeletesAndUsesPrivateModes(): void
        {
            $base = $this->temporaryDirectory();
            $root = $base . '/nested/storage';
            $canonicalRoot = (realpath($base) ?: $base) . '/nested/storage';
            $source = $this->fixture('source.txt', 'private-content');
            $download = $this->temporaryDirectory() . '/download.txt';
            $driver = new LocalStorageDriver($root, true);

            $driver->put('tenant/file.txt', $source);

            $stored = $canonicalRoot . '/tenant/file.txt';
            self::assertSame($stored, $driver->localPath('tenant/file.txt'));
            self::assertSame('private-content', file_get_contents($stored));
            self::assertSame(0600, fileperms($stored) & 0777);
            self::assertSame(0700, fileperms(dirname($stored)) & 0777);

            $driver->downloadTo('tenant/file.txt', $download);
            self::assertSame('private-content', file_get_contents($download));
            self::assertSame(0600, fileperms($download) & 0777);

            $driver->delete('tenant/file.txt');
            self::assertFileDoesNotExist($stored);
            $driver->delete('tenant/file.txt');
        }

        public function testLocalDriverRejectsSymlinkedParentEscape(): void
        {
            if (!function_exists('symlink')) {
                self::markTestSkipped('Symbolic links are unavailable.');
            }

            $root = $this->temporaryDirectory() . '/storage';
            $outside = $this->temporaryDirectory();
            self::assertTrue(mkdir($root, 0700));
            self::assertTrue(symlink($outside, $root . '/escape'));
            $driver = new LocalStorageDriver($root, true);

            try {
                $driver->put('escape/object.txt', $this->fixture('source.txt', 'secret'));
                self::fail('Expected the symlink escape to be rejected.');
            } catch (\RuntimeException) {
                self::assertFileDoesNotExist($outside . '/object.txt');
            }
        }

        public function testLocalDriverRejectsSymlinkObjectForReadDeleteAndLocalPath(): void
        {
            if (!function_exists('symlink')) {
                self::markTestSkipped('Symbolic links are unavailable.');
            }

            $root = $this->temporaryDirectory() . '/storage';
            $outside = $this->fixture('outside.txt', 'outside');
            self::assertTrue(mkdir($root, 0700));
            self::assertTrue(symlink($outside, $root . '/object.txt'));
            $driver = new LocalStorageDriver($root, true);

            foreach (['localPath', 'delete', 'downloadTo'] as $operation) {
                try {
                    $operation === 'downloadTo'
                        ? $driver->{$operation}('object.txt', $this->temporaryDirectory() . '/download.txt')
                        : $driver->{$operation}('object.txt');
                    self::fail(sprintf('Expected %s to reject the symlink.', $operation));
                } catch (\RuntimeException) {
                    self::assertSame('outside', file_get_contents($outside));
                }
            }
        }

        public function testLocalPutFailureLeavesExistingTargetAndNoTemporaryFile(): void
        {
            $root = $this->temporaryDirectory() . '/storage';
            self::assertTrue(mkdir($root, 0700));
            self::assertSame(8, file_put_contents($root . '/safe.txt', 'original'));
            $driver = new LocalStorageDriver($root, true);

            try {
                $driver->put('safe.txt', $this->temporaryDirectory() . '/missing.txt');
                self::fail('Expected an unreadable source to fail.');
            } catch (\RuntimeException) {
                self::assertSame('original', file_get_contents($root . '/safe.txt'));
                self::assertSame([], glob($root . '/.peanut-storage-*') ?: []);
            }
        }

        public function testAliyunDriverMapsPrivateProviderOperations(): void
        {
            $client = new OssClient();
            $driver = new AliyunStorageDriver($client, 'bucket-a');
            $source = $this->fixture('aliyun.txt', 'aliyun');

            $driver->put('tenant/object.txt', $source);
            $driver->delete('tenant/object.txt');
            $driver->downloadTo('tenant/object.txt', '/host/download.txt');

            self::assertSame([
                ['method' => 'uploadFile', 'arguments' => [
                    'bucket-a',
                    'tenant/object.txt',
                    $source,
                    [OssClient::OSS_HEADERS => [OssClient::OSS_OBJECT_ACL => OssClient::OSS_ACL_TYPE_PRIVATE]],
                ]],
                ['method' => 'deleteObject', 'arguments' => ['bucket-a', 'tenant/object.txt']],
                ['method' => 'getObject', 'arguments' => [
                    'bucket-a',
                    'tenant/object.txt',
                    [OssClient::OSS_FILE_DOWNLOAD => '/host/download.txt'],
                ]],
            ], $client->calls);
            self::assertNull($driver->localPath('tenant/object.txt'));
        }

        public function testQcloudDriverMapsPrivateProviderOperationsAndClosesSource(): void
        {
            $client = new Client();
            $driver = new QcloudStorageDriver($client, 'bucket-q');
            $source = $this->fixture('qcloud.txt', 'qcloud');

            $driver->put('tenant/object.txt', $source);
            $driver->delete('tenant/object.txt');
            $driver->downloadTo('tenant/object.txt', '/host/download.txt');

            $put = $client->calls[0];
            self::assertSame('putObject', $put['method']);
            self::assertSame('bucket-q', $put['arguments'][0]['Bucket']);
            self::assertSame('tenant/object.txt', $put['arguments'][0]['Key']);
            self::assertSame('private', $put['arguments'][0]['ACL']);
            self::assertSame('qcloud', $put['arguments'][0]['BodyContents']);
            self::assertFalse(is_resource($put['arguments'][0]['Body']));
            self::assertSame(
                ['method' => 'deleteObject', 'arguments' => [[
                    'Bucket' => 'bucket-q',
                    'Key' => 'tenant/object.txt',
                ]]],
                $client->calls[1],
            );
            self::assertSame(
                ['method' => 'download', 'arguments' => ['bucket-q', 'tenant/object.txt', '/host/download.txt']],
                $client->calls[2],
            );
            self::assertNull($driver->localPath('tenant/object.txt'));
        }

        public function testQiniuDriverMapsUploadDeleteAndPrivateDownloadTransport(): void
        {
            $auth = new Auth();
            $transport = new FakeStorageHttpTransport([
                ['status' => 200, 'body' => '{"key":"tenant/object.txt"}'],
                ['status' => 204, 'body' => ''],
                ['status' => 200, 'body' => ''],
            ]);
            $driver = new QiniuStorageDriver($auth, 'bucket-7', '', 'https://private.example.test/', $transport);

            $driver->put('tenant/object.txt', $this->fixture('qiniu.txt', 'qiniu'));
            $driver->delete('tenant/object.txt');
            $driver->downloadTo('tenant/object.txt', '/host/download.txt');

            $upload = $transport->requests[0];
            self::assertSame('POST', $upload['method']);
            self::assertSame('https://upload.qiniup.com', $upload['url']);
            self::assertSame(120, $upload['timeout']);
            self::assertSame('upload-token', $upload['multipart'][0]['contents']);
            self::assertSame('tenant/object.txt', $upload['multipart'][1]['contents']);
            self::assertSame('qiniu', $upload['multipart'][2]['consumedContents']);
            self::assertSame('object.txt', $upload['multipart'][2]['filename']);
            self::assertFalse(is_resource($upload['multipart'][2]['contents']));

            $deleteUrl = 'https://rs.qiniuapi.com/delete/encoded-bucket-7%3Atenant%2Fobject.txt';
            self::assertSame([
                'method' => 'POST',
                'url' => $deleteUrl,
                'headers' => ['Authorization' => 'QBox signed'],
                'timeout' => 30,
            ], $transport->requests[1]);
            self::assertSame([
                'method' => 'GET',
                'url' => 'https://private.example.test/tenant/object.txt?signed=60',
                'retrySafe' => true,
                'sink' => '/host/download.txt',
            ], $transport->requests[2]);
            self::assertSame([
                ['method' => 'uploadToken', 'arguments' => ['bucket-7']],
                ['method' => 'authorization', 'arguments' => [$deleteUrl, null, 'application/x-www-form-urlencoded']],
                ['method' => 'privateDownloadUrl', 'arguments' => ['https://private.example.test/tenant/object.txt', 60]],
            ], $auth->calls);
            self::assertNull($driver->localPath('tenant/object.txt'));
        }

        public function testQiniuUploadRejectsProviderKeyMismatch(): void
        {
            $driver = new QiniuStorageDriver(
                new Auth(),
                'bucket',
                'https://upload.example.test/',
                'https://download.example.test',
                new FakeStorageHttpTransport([['status' => 200, 'body' => '{"key":"other.txt"}']]),
            );

            $this->expectException(\RuntimeException::class);
            $driver->put('expected.txt', $this->fixture('qiniu-mismatch.txt', 'content'));
        }

        public function testQiniuDownloadRequiresConfiguredDomainBeforeTransport(): void
        {
            $transport = new FakeStorageHttpTransport([]);
            $driver = new QiniuStorageDriver(new Auth(), 'bucket', '', '', $transport);

            try {
                $driver->downloadTo('object.txt', '/host/download.txt');
                self::fail('Expected a missing private domain to fail closed.');
            } catch (\RuntimeException) {
                self::assertSame([], $transport->requests);
            }
        }

        protected function tearDown(): void
        {
            foreach (array_reverse($this->temporaryDirectories) as $directory) {
                $this->removeDirectory($directory);
            }
        }

        private function temporaryDirectory(): string
        {
            $directory = sys_get_temp_dir() . '/peanut-storage-contract-' . bin2hex(random_bytes(8));
            if (!mkdir($directory, 0700)) {
                throw new \RuntimeException('Cannot create storage contract fixture.');
            }
            $this->temporaryDirectories[] = $directory;

            return $directory;
        }

        private function fixture(string $name, string $contents): string
        {
            $path = $this->temporaryDirectory() . '/' . $name;
            if (file_put_contents($path, $contents) === false) {
                throw new \RuntimeException('Cannot create storage contract file.');
            }

            return $path;
        }

        private function removeDirectory(string $directory): void
        {
            if (!is_dir($directory) || is_link($directory)) {
                return;
            }
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $directory . '/' . $entry;
                if (is_dir($path) && !is_link($path)) {
                    $this->removeDirectory($path);
                } else {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /** In-memory transport that captures provider requests without network access. */
    final class FakeStorageHttpTransport implements StorageHttpTransport
    {
        /** @var list<array<string, mixed>> */
        public array $requests = [];

        /** @param list<array{status: int, body: string}> $responses */
        public function __construct(private array $responses) {}

        public function request(array $request): array
        {
            foreach ($request['multipart'] ?? [] as $index => $part) {
                if (is_resource($part['contents'])) {
                    $request['multipart'][$index]['consumedContents'] = stream_get_contents($part['contents']);
                    fclose($part['contents']);
                }
            }
            $this->requests[] = $request;

            return array_shift($this->responses) ?? throw new \RuntimeException('Unexpected transport request.');
        }
    }
}
