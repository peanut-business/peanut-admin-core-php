<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Media;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;

/** QR generation is optional; SVG does not require GD or Imagick. */
final class QrCodeGenerator
{
    public function svg(string $content, int $size = 300): string
    {
        if (!class_exists(Builder::class)) {
            throw new \LogicException('Install endroid/qr-code to generate QR codes.');
        }
        return (new Builder(writer: new SvgWriter()))->build(data: $content, size: $size)->getString();
    }
}
