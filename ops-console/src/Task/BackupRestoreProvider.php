<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Task;

interface BackupRestoreProvider
{
    public function key(): string;

    public function backupHandlerKey(): string;

    public function restoreHandlerKey(): string;

    /** @return list<string> */
    public function restoreTargetKeys(): array;

    public function maximumAttempts(): int;
}
