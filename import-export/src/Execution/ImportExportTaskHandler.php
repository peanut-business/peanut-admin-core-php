<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport\Execution;

use PeanutAdmin\ImportExport\Application\ImportExportException;
use PeanutAdmin\ImportExport\Application\ImportExportService;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\TaskJob\Execution\JobExecution;
use PeanutAdmin\TaskJob\Execution\TaskHandler;

final readonly class ImportExportTaskHandler implements TaskHandler
{
    public function __construct(private CsvOperationRunner $runner) {}
    public function key(): string
    {
        return 'peanut.import-export.execute';
    }

    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        if (!hash_equals(ImportExportService::RESOURCE_KEY, $context->resourceKey)
            || !hash_equals('create', $context->operation)
            || array_keys($execution->payload) !== ['operation_key']
            || !is_string($execution->payload['operation_key'])) {
            throw ImportExportException::denied();
        }
        $this->runner->run($context, $execution->payload['operation_key'], $execution->jobKey, $execution->attemptNumber);
    }
}
