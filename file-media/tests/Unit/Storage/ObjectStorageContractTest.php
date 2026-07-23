<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Tests\Unit\Storage;

use PeanutAdmin\FileMedia\Storage\ObjectStorageCapabilities;
use PeanutAdmin\FileMedia\Storage\PrivateStorageAdapter;
use PeanutAdmin\FileMedia\Storage\StorageProvider;
use PeanutAdmin\FileMedia\Storage\StoredObject;
use PHPUnit\Framework\TestCase;

final class ObjectStorageContractTest extends TestCase
{
    public function testExistingPrivateProviderHasFailClosedObjectStorageCapabilities(): void
    {
        $provider = new class implements StorageProvider {
            public function key(): string
            {
                return 'local-dev';
            }
            public function store(int $tenantId, string $fileKey, string $sourcePath): StoredObject
            {
                return new StoredObject($this->key(), 'opaque');
            }
            public function open(string $storageKey)
            {
                $stream = fopen('php://temp', 'w+b');
                if ($stream === false) {
                    throw new \RuntimeException('Cannot create object fixture.');
                }
                fwrite($stream, 'private');
                rewind($stream);
                return $stream;
            }
            public function remove(string $storageKey): void {}
        };
        $adapter = new PrivateStorageAdapter($provider);

        self::assertSame('local-dev', $adapter->key());
        self::assertFalse($adapter->capabilities()->publicObjects);
        self::assertFalse($adapter->capabilities()->signedDelivery);
        self::assertSame(hash('sha256', 'private'), $adapter->head('opaque')->sha256);
        self::assertSame(['size_bytes' => 7, 'sha256' => hash('sha256', 'private')], $adapter->head('opaque')->publicEvidence());
    }

    public function testCdnCannotBeAdvertisedWithoutSignedDelivery(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ObjectStorageCapabilities(cdnDelivery: true);
    }

    public function testPublicObjectsCannotBeAdvertisedWithoutSignedDelivery(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ObjectStorageCapabilities(publicObjects: true);
    }
}
