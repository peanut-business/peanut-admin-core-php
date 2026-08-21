<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Submission;

use JsonException;
use PeanutAdmin\Kernel\Async\TrustedEnvelopeCodec;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\TaskJob\Application\JobRecord;
use PeanutAdmin\TaskJob\Application\TaskJobException;
use PeanutAdmin\TaskJob\Persistence\PdoTaskJobRepository;

final readonly class TrustedJobPublisher
{
    public function __construct(
        private PdoTaskJobRepository $repository,
        private TaskSubmissionRegistry $submissions,
        private TrustedEnvelopeCodec $envelopes,
    ) {}

    /** @param array<string, mixed> $input */
    public function publish(
        AuthorizedOperationContext $context,
        string $taskType,
        array $input,
        string $idempotencyKey,
    ): JobRecord {
        if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 200 || preg_match('/^[\x21-\x7e]+$/D', $idempotencyKey) !== 1) {
            throw TaskJobException::invalid();
        }
        $provider = $this->submissions->require($taskType);
        if (!hash_equals($provider->resourceKey(), $context->resourceKey)
            || !hash_equals($provider->operation(), $context->operation)
        ) {
            throw TaskJobException::denied();
        }
        $submission = $provider->build($context, $input);
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $submission->handlerKey) !== 1
            || $submission->maxAttempts < 1 || $submission->maxAttempts > 10
            || $submission->initialDelaySeconds < 0 || $submission->initialDelaySeconds > 86400
        ) {
            throw TaskJobException::invalid();
        }
        $payload = $this->canonicalJson($submission->payload);
        if (strlen($payload) > 65535) {
            throw TaskJobException::invalid();
        }
        $jobKey = 'job_' . bin2hex(random_bytes(16));
        $requestHash = hash('sha256', $this->canonicalJson([
            'task_type' => $taskType,
            'handler_key' => $submission->handlerKey,
            'payload' => $submission->payload,
            'max_attempts' => $submission->maxAttempts,
            'initial_delay_seconds' => $submission->initialDelaySeconds,
        ]));
        $job = $this->repository->enqueue(
            $context->tenantContext->tenantId,
            $context->tenantContext->memberId,
            $jobKey,
            $taskType,
            $submission->handlerKey,
            $payload,
            $this->envelopes->issue($context, $jobKey, $context->tenantContext->requestId),
            hash('sha256', $idempotencyKey),
            $requestHash,
            $submission->maxAttempts,
            $submission->initialDelaySeconds,
        );
        return $job;
    }

    /** @throws JsonException */
    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->normalize($value, 0), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function normalize(mixed $value, int $depth): mixed
    {
        if ($depth > 16) {
            throw TaskJobException::invalid();
        }
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                if (!is_int($key) && !is_string($key)) {
                    throw TaskJobException::invalid();
                }
                $value[$key] = $this->normalize($item, $depth + 1);
            }
            return $value;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        throw TaskJobException::invalid();
    }
}
