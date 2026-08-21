<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Tests\Unit\Application;

use PeanutAdmin\FileMedia\Application\FileMediaException;
use PeanutAdmin\FileMedia\Application\UploadPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UploadPolicyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/peanut-file-policy-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);
    }

    public function testItDetectsTheServerMediaTypeHashAndExactByteCount(): void
    {
        $path = $this->directory . '/upload.bin';
        self::assertSame(12, file_put_contents($path, "hello world\n"));

        $upload = (new UploadPolicy(['text/plain'], 32))->inspect($path, '../report.txt');

        self::assertSame('report.txt', $upload->originalName);
        self::assertSame('text/plain', $upload->mediaType);
        self::assertSame(12, $upload->sizeBytes);
        self::assertSame(hash('sha256', "hello world\n"), $upload->sha256);
    }

    public function testItRejectsEmptyOversizedAndDeniedUploads(): void
    {
        $empty = $this->directory . '/empty';
        touch($empty);
        $this->assertFileError('FILE_UPLOAD_INVALID', fn() => (new UploadPolicy())->inspect($empty, 'empty.txt'));

        $large = $this->directory . '/large';
        file_put_contents($large, '1234');
        $this->assertFileError('FILE_TOO_LARGE', fn() => (new UploadPolicy(['text/plain'], 3))->inspect($large, 'large.txt'));

        $denied = $this->directory . '/denied';
        file_put_contents($denied, "plain text\n");
        $this->assertFileError('FILE_MEDIA_TYPE_DENIED', fn() => (new UploadPolicy(['application/pdf']))->inspect($denied, 'fake.pdf'));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeNames(): iterable
    {
        yield 'empty' => [''];
        yield 'nul' => ["bad\0name.txt"];
        yield 'dot' => ['.'];
        yield 'dot-dot' => ['..'];
        yield 'control only' => ["\x01\x02"];
    }

    #[DataProvider('unsafeNames')]
    public function testItRejectsUnsafeDisplayNames(string $name): void
    {
        $this->assertFileError('FILE_UPLOAD_INVALID', fn() => UploadPolicy::normalizeOriginalName($name));
    }

    public function testItLimitsTheDisplayNameByUtf8Characters(): void
    {
        self::assertSame(str_repeat('文', 255), UploadPolicy::normalizeOriginalName(str_repeat('文', 255)));
        $this->assertFileError(
            'FILE_UPLOAD_INVALID',
            fn() => UploadPolicy::normalizeOriginalName(str_repeat('文', 256)),
        );
    }

    public function testHostPolicyCanOnlyNarrowDefaults(): void
    {
        new UploadPolicy(['image/png'], 1024);
        $this->assertFileError('FILE_UPLOAD_INVALID', fn() => new UploadPolicy(['application/octet-stream']));
        $this->assertFileError('FILE_UPLOAD_INVALID', fn() => new UploadPolicy([], 1));
        $this->assertFileError('FILE_UPLOAD_INVALID', fn() => new UploadPolicy(maxBytes: UploadPolicy::DEFAULT_MAX_BYTES + 1));
    }

    private function assertFileError(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected {$code}");
        } catch (FileMediaException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}
