<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage\Driver;

use PeanutAdmin\FileMedia\Storage\StorageDriver;
use PeanutAdmin\FileMedia\Storage\StorageObjectKey;
use Qcloud\Cos\Client;

/** Low-level Tencent COS driver using a Host-assembled SDK client. */
final readonly class QcloudStorageDriver implements StorageDriver
{
    public function __construct(
        private Client $client,
        private string $bucket,
    ) {}

    /** @inheritDoc */
    public function put(string $objectKey, string $sourcePath): void
    {
        $stream = fopen($sourcePath, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException('待上传文件不可读');
        }

        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => StorageObjectKey::assert($objectKey),
                'Body' => $stream,
                'ACL' => 'private',
            ]);
        } finally {
            // The SDK may consume and close the source stream before returning.
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** @inheritDoc */
    public function delete(string $objectKey): void
    {
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => StorageObjectKey::assert($objectKey),
        ]);
    }

    /** @inheritDoc */
    public function downloadTo(string $objectKey, string $targetPath): void
    {
        $this->client->download(
            $this->bucket,
            StorageObjectKey::assert($objectKey),
            $targetPath,
        );
    }

    /** @inheritDoc */
    public function localPath(string $objectKey): ?string
    {
        return null;
    }
}
