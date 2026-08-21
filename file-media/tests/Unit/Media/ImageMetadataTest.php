<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Tests\Unit\Media;

use PeanutAdmin\FileMedia\Application\FileMediaException;
use PeanutAdmin\FileMedia\Media\ImageInspection;
use PeanutAdmin\FileMedia\Media\ImageMetadata;
use PeanutAdmin\FileMedia\Media\ImageMetadataInspector;
use PeanutAdmin\FileMedia\Media\ImageVariantDefinition;
use PeanutAdmin\FileMedia\Media\ImageVariantOutput;
use PeanutAdmin\FileMedia\Media\ImageVariantOutputVerifier;
use PeanutAdmin\FileMedia\Media\ImageVariantPlan;
use PeanutAdmin\FileMedia\Media\ImageVariantPlanner;
use PHPUnit\Framework\TestCase;

final class ImageMetadataTest extends TestCase
{
    public function testInspectsImageAndBuildsBoundedVariantPlans(): void
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
            $plans = (new ImageVariantPlanner())->plan($metadata, [new ImageVariantDefinition('thumb', 320, 320)]);
            self::assertSame(['variant_key' => 'thumb', 'width' => 2, 'height' => 3, 'fit' => 'cover', 'media_type' => 'image/jpeg'], $plans[0]->publicMetadata());
            self::assertSame('variants/thumb.jpg', $plans[0]->storageSuffix);

            $pngPlan = (new ImageVariantPlanner())->plan($metadata, [new ImageVariantDefinition('original', 2, 3, 'contain', 'image/png')])[0];
            $output = (new ImageVariantOutputVerifier())->verify($path, $pngPlan);
            self::assertSame(hash_file('sha256', $path), $output->sha256);
            self::assertSame(['variant_key', 'width', 'height', 'media_type', 'size_bytes', 'sha256'], array_keys($output->persistenceMetadata()));
            $this->expectImageError(fn() => (new ImageMetadataInspector($size - 1))->inspect($path));
            $this->expectImageError(fn() => (new ImageVariantOutputVerifier($size - 1))->verify($path, $pngPlan));
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsNonImageSymlinkAndDuplicateVariant(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'peanut-image-');
        self::assertIsString($path);
        file_put_contents($path, 'not an image');
        try {
            $this->expectImageError(fn() => (new ImageMetadataInspector())->inspect($path));
        } finally {
            @unlink($path);
        }
    }

    public function testVariantValueObjectsCannotBeForged(): void
    {
        $valid = new ImageVariantPlan('thumb', 100, 80, 'cover', 'image/jpeg', 'variants/thumb.jpg');
        $hardCap = ImageMetadataInspector::HARD_MAX_BYTES;
        $output = new ImageVariantOutput($valid, $hardCap, str_repeat('a', 64));
        self::assertSame('thumb', $output->plan->variantKey);
        self::assertSame($hardCap, $output->persistenceMetadata()['size_bytes']);
        $inspection = new ImageInspection(new ImageMetadata(100, 80, 'image/jpeg'), $hardCap, str_repeat('b', 64));
        self::assertSame($hardCap, $inspection->sizeBytes);

        $this->expectImageError(fn() => new ImageVariantPlan('thumb', 100, 80, 'cover', 'image/jpeg', '../thumb.jpg'));
        $this->expectImageError(fn() => new ImageVariantPlan('thumb', 0, 80, 'cover', 'image/jpeg', 'variants/thumb.jpg'));
        $this->expectImageError(fn() => new ImageVariantPlan('thumb', 100, 80, 'stretch', 'image/jpeg', 'variants/thumb.jpg'));
        $this->expectImageError(fn() => new ImageVariantPlan('thumb', 100, 80, 'cover', 'text/plain', 'variants/thumb.jpg'));
        $this->expectImageError(fn() => new ImageVariantOutput($valid, 0, str_repeat('a', 64)));
        $this->expectImageError(fn() => new ImageVariantOutput($valid, $hardCap + 1, str_repeat('a', 64)));
        $this->expectImageError(fn() => new ImageVariantOutput($valid, 10, '../not-a-hash'));
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
