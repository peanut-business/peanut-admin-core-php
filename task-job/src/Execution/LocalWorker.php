<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Execution;

use PeanutAdmin\Kernel\Async\JobHandlerAdapter;
use PeanutAdmin\TaskJob\Application\TaskJobException;
use PeanutAdmin\TaskJob\Persistence\PdoTaskJobRepository;
use Throwable;

final readonly class LocalWorker
{
    public function __construct(
        private int $tenantId,
        private string $workerId,
        private PdoTaskJobRepository $repository,
        private TaskHandlerRegistry $handlers,
        private JobHandlerAdapter $authorization,
        private int $leaseSeconds = 60,
    ) {
        if ($tenantId < 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $workerId) !== 1
            || $leaseSeconds < 5 || $leaseSeconds > 3600
        ) {
            throw TaskJobException::invalid();
        }
    }

    public function runOnce(): ?string
    {
        $claim = $this->repository->claim($this->tenantId, $this->workerId, $this->leaseSeconds);
        if ($claim === null) {
            return null;
        }
        try {
            $handler = $this->handlers->require($claim->handlerKey);
            $this->authorization->handle(
                $claim->trustedEnvelope,
                function ($context, $envelope) use ($claim, $handler): void {
                    if ($envelope->tenantId !== $claim->tenantId
                        || $context->tenantContext->tenantId !== $claim->tenantId
                        || !hash_equals($envelope->operationId, $claim->jobKey)
                    ) {
                        throw TaskJobException::denied();
                    }
                    $handler->handle($context, new JobExecution(
                        $claim->jobKey,
                        $claim->tenantId,
                        $claim->attemptNumber,
                        $claim->payload,
                    ));
                },
            );
        } catch (RetryableTaskException $exception) {
            $status = $this->repository->fail($claim, $exception->safeCode, true, $this->backoff($claim->attemptNumber));
            return $status;
        } catch (Throwable $exception) {
            $code = $exception instanceof TaskJobException ? $exception->problemCode : 'TASK_HANDLER_FAILED';
            $status = $this->repository->fail($claim, $code, false, 0);
            return $status;
        }
        $this->repository->succeed($claim);
        return 'succeeded';
    }

    public function renew(JobClaim $claim): void
    {
        if ($claim->tenantId !== $this->tenantId) {
            throw TaskJobException::denied();
        }
        $this->repository->renew($claim, $this->leaseSeconds);
    }

    private function backoff(int $attempt): int
    {
        return min(300, 5 * (2 ** max(0, $attempt - 1)));
    }
}
