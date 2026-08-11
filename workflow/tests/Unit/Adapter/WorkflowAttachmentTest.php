<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Tests\Unit\Adapter;

use PeanutAdmin\Workflow\Adapter\WorkflowAttachment;
use PeanutAdmin\Workflow\Application\WorkflowException;
use PHPUnit\Framework\TestCase;

final class WorkflowAttachmentTest extends TestCase
{
    public function testProjectsOnlyTheImmutableApprovedFileSnapshot(): void
    {
        $attachment = new WorkflowAttachment(
            'file_' . str_repeat('a', 32),
            'submission.txt',
            'text/plain',
            123,
            str_repeat('b', 64),
        );
        self::assertSame([
            'file_key' => 'file_' . str_repeat('a', 32),
            'name' => 'submission.txt',
            'media_type' => 'text/plain',
            'size_bytes' => 123,
            'sha256' => str_repeat('b', 64),
        ], $attachment->toArray());
    }

    public function testRejectsMutableOrMalformedSnapshotShape(): void
    {
        $this->expectException(WorkflowException::class);
        new WorkflowAttachment('file-invalid', "bad\0name", 'text/plain', -1, 'not-a-digest');
    }
}
