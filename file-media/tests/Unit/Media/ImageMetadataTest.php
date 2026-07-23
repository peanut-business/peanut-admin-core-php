<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Tests\Unit\Media;

use PeanutAdmin\FileMedia\Application\FileMediaException;
use PeanutAdmin\FileMedia\Media\ImageMetadataInspector;
use PeanutAdmin\FileMedia\Media\ImageVariantDefinition;
use PeanutAdmin\FileMedia\Media\ImageVariantOutputVerifier;
use PeanutAdmin\FileMedia\Media\ImageVariantPlanner;
use PHPUnit\Framework\TestCase;

final class ImageMetadataTest extends TestCase
{
    public function testInspectsImageAndBuildsBoundedVariantPlans(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'peanut-image-');
        self::assertIsString($path);
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAD0lEQVR4nGP4z8DAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==', true));
        try {
            $metadata = (new ImageMetadataInspector())->inspect($path);
            self::assertSame(['width' => 2, 'height' => 3, 'media_type' => 'image/png'], $metadata->toArray());
            $plans = (new ImageVariantPlanner())->plan($metadata, [new ImageVariantDefinition('thumb', 320, 320)]);
            self::assertSame(['variant_key' => 'thumb', 'width' => 2, 'height' => 3, 'fit' => 'cover', 'media_type' => 'image/jpeg'], $plans[0]->publicMetadata());
            self::assertSame('variants/thumb.jpg', $plans[0]->storageSuffix);

            $pngPlan = (new ImageVariantPlanner())->plan($metadata, [new ImageVariantDefinition('original', 2, 3, 'contain', 'image/png')])[0];
            $output = (new ImageVariantOutputVerifier())->verify($path, $pngPlan);
            self::assertSame(hash_file('sha256', $path), $output->sha256);
            self::assertSame(['variant_key', 'width', 'height', 'media_type', 'size_bytes', 'sha256'], array_keys($output->persistenceMetadata()));
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
