<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Tests\Unit\Media;

use PeanutAdmin\FileMedia\Application\FileMediaException;
use PeanutAdmin\FileMedia\Media\ImageInspection;
use PeanutAdmin\FileMedia\Media\ImageMetadata;
use PeanutAdmin\FileMedia\Media\ImageMetadataInspector;
use PeanutAdmin\FileMedia\Media\ImageVariantDefinition;
use PHPUnit\Framework\TestCase;

final class ImageMetadataTest extends TestCase
{
    public function testInspectsImageWithBoundedByteAndHashEvidence(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'peanut-image-');
        self::assertIsString($path);
        $size = file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAD0lEQVR4nGP4z8DAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==', true));
        self::assertIsInt($size);
        try {
            $inspection = (new ImageMetadataInspector($size))->inspectWithEvidence($path);
            $metadata = $inspection->metadata;
            self::assertSame(['width' => 2, 'height' => 3, 'media_type' => 'image/png'], $metadata->toArray());
            self::assertSame($size, $inspection->sizeBytes);
            self::assertSame(hash_file('sha256', $path), $inspection->sha256);
            self::assertSame($metadata->toArray(), (new ImageMetadataInspector($size))->inspect($path)->toArray());
            $this->expectImageError(fn() => (new ImageMetadataInspector($size - 1))->inspect($path));
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsNonImageAndSymlink(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'peanut-image-');
        self::assertIsString($path);
        file_put_contents($path, 'not an image');
        $link = $path . '-link';
        try {
            $this->expectImageError(fn() => (new ImageMetadataInspector())->inspect($path));
            file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAD0lEQVR4nGP4z8DAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==', true));
            self::assertTrue(symlink($path, $link));
            $this->expectImageError(fn() => (new ImageMetadataInspector())->inspect($link));
        } finally {
            @unlink($link);
            @unlink($path);
        }
    }

    public function testVariantDefinitionsAndInspectionEvidenceRejectInvalidValues(): void
    {
        $valid = new ImageVariantDefinition('thumb', 100, 80, 'cover', 'image/jpeg');
        $hardCap = ImageMetadataInspector::HARD_MAX_BYTES;
        self::assertSame('thumb', $valid->key);
        $inspection = new ImageInspection(new ImageMetadata(100, 80, 'image/jpeg'), $hardCap, str_repeat('b', 64));
        self::assertSame($hardCap, $inspection->sizeBytes);

        $this->expectImageError(fn() => new ImageVariantDefinition('../thumb', 100, 80));
        $this->expectImageError(fn() => new ImageVariantDefinition('thumb', 0, 80));
        $this->expectImageError(fn() => new ImageVariantDefinition('thumb', 4097, 80));
        $this->expectImageError(fn() => new ImageVariantDefinition('thumb', 100, 80, 'stretch'));
        $this->expectImageError(fn() => new ImageVariantDefinition('thumb', 100, 80, 'cover', 'text/plain'));
        $this->expectImageError(fn() => new ImageInspection($inspection->metadata, 0, str_repeat('a', 64)));
        $this->expectImageError(fn() => new ImageInspection($inspection->metadata, 10, '../not-a-hash'));
        $this->expectImageError(fn() => new ImageInspection(
            new ImageMetadata(100, 80, 'image/jpeg'),
            $hardCap + 1,
            str_repeat('b', 64),
        ));
    }

    private function expectImageError(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected FILE_IMAGE_INVALID');
        } catch (FileMediaException $exception) {
            self::assertSame('FILE_IMAGE_INVALID', $exception->errorCode);
        }
    }
}
