<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Logs;

use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\OpsConsole\Application\OpsConsoleException;
use PeanutAdmin\OpsConsole\Application\PlatformPermissionChecker;
use PeanutAdmin\OpsConsole\Package;
use Throwable;

final readonly class RuntimeLogService
{
    public function __construct(
        private PlatformPermissionChecker $permissions,
        private RuntimeLogProviderRegistry $providers,
        private SafeLogMessageCatalog $messages,
    ) {}

    public function read(PlatformContext $context, RuntimeLogQuery $query): RuntimeLogPage
    {
        if (!$this->permissions->allows($context, Package::LOGS_PERMISSION)) {
            throw OpsConsoleException::denied();
        }
        try {
            $batch = $this->providers->require($query->sourceKey)->read($context, $query);
            return new RuntimeLogPage(array_map(
                fn(StructuredLogRecord $record): RuntimeLogEntry => new RuntimeLogEntry(
                    $record, $this->messages->message($record->eventKey),
                ),
                $batch->records,
            ), $batch->nextCursor);
        } catch (OpsConsoleException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw OpsConsoleException::logsUnavailable();
        }
    }
}
