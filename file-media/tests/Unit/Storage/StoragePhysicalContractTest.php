<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Tests\Unit\Storage;

use PeanutAdmin\FileMedia\Storage\Driver\LocalStorageDriver;
use PeanutAdmin\FileMedia\Storage\StorageCapabilityUnsupported;
use PeanutAdmin\FileMedia\Storage\StorageObjectObservation;
use PeanutAdmin\FileMedia\Storage\StorageObjectVersion;
use PeanutAdmin\FileMedia\Storage\StorageVersionCursor;
use PHPUnit\Framework\TestCase;

final class StoragePhysicalContractTest extends TestCase
{
    public function testLocalMigrationUsesActualBytesAndKeepsOldPathDeletionDistinct(): void
    {
        $root = sys_get_temp_dir() . '/peanut-physical-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        try {
            file_put_contents($root . '/source', 'private bytes');
            $public = new LocalStorageDriver($root . '/public', false);
            $private = new LocalStorageDriver($root . '/private', true);
            $public->put('exact/file.txt', $root . '/source');
            $public->put('exact/file.txt-neighbour', $root . '/source');
            $observed = $public->inspect('exact/file.txt');
            self::assertTrue($public->capabilities()->objectInspection);
            self::assertFalse($public->capabilities()->objectAclRestriction);
            self::assertTrue($observed->exists);
            self::assertSame(13, $observed->sizeBytes);
            self::assertSame('text/plain', $observed->mediaType);
            self::assertTrue($observed->mediaTypeVerified);
            self::assertNull($observed->etag);
            $public->downloadTo('exact/file.txt', $root . '/transfer');
            $private->put('exact/file.txt', $root . '/transfer');
            self::assertSame(hash_file('sha256', $root . '/source'), hash_file('sha256', $private->localPath('exact/file.txt')));
            self::assertTrue($public->inspect('exact/file.txt')->exists);
            self::assertSame('acknowledged', $public->delete('exact/file.txt')->outcome);
            self::assertFalse($public->inspect('exact/file.txt')->exists);
            self::assertSame('already-absent', $public->delete('exact/file.txt')->outcome);
            self::assertTrue($public->inspect('exact/file.txt-neighbour')->exists);
            self::assertTrue($private->inspect('exact/file.txt')->exists);
        } finally {
            $this->remove($root);
        }
    }

    public function testLocalRejectsAllHistoricalVersionOperationsAndAclWithoutChangingBytes(): void
    {
        $root = sys_get_temp_dir() . '/peanut-version-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        file_put_contents($root . '/object.txt', 'keep');
        $driver = new LocalStorageDriver($root, false);
        try {
            foreach ([
                fn() => $driver->inspect('object.txt', 'old-version'),
                fn() => $driver->delete('object.txt', 'old-version'),
                fn() => $driver->downloadTo('object.txt', $root . '/download', 'old-version'),
                fn() => $driver->restrictToPrivate('object.txt'),
                fn() => $driver->listVersions('object.txt'),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('Unsupported physical primitive must not succeed.');
                } catch (StorageCapabilityUnsupported) {
                    self::assertSame('keep', file_get_contents($root . '/object.txt'));
                }
            }
            self::assertFileDoesNotExist($root . '/download');
        } finally {
            $this->remove($root);
        }
    }

    public function testLocalInspectRejectsSymlinkWithoutReadingOutsideBytes(): void
    {
        $root = sys_get_temp_dir() . '/peanut-observe-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        file_put_contents($root . '/outside', 'secret');
        mkdir($root . '/storage', 0700);
        symlink($root . '/outside', $root . '/storage/link');
        try {
            $this->expectException(\RuntimeException::class);
            (new LocalStorageDriver($root . '/storage', true))->inspect('link');
        } finally {
            $this->remove($root);
        }
    }

    public function testObservationRejectsInventedMimeAndAbsentObjectMetadata(): void
    {
        foreach ([
            fn() => new StorageObjectObservation(true, 3, "text/plain\r\nInjected: yes"),
            fn() => new StorageObjectObservation(false, 3),
            fn() => new StorageObjectVersion('old', false, false, -1),
            fn() => new StorageVersionCursor('../other', 'old'),
        ] as $constructor) {
            try {
                $constructor();
                self::fail('Invalid observation must be rejected.');
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    $this->remove($path . '/' . $name);
                }
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
