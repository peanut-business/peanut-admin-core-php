<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Adapter;

use JsonException;
use PeanutAdmin\Workflow\Application\WorkflowException;

final readonly class WorkflowTransitionEffects
{
    /**
     * @param list<WorkflowNotificationIntent> $notificationIntents
     * @param list<WorkflowTaskIntent> $taskIntents
     */
    public function __construct(
        public string $instanceKey,
        public int $eventSequence,
        public string $transitionKey,
        public string $subjectRevisionKey,
        array $notificationIntents,
        array $taskIntents,
    ) {
        if (preg_match('/^instance_[0-9a-f]{32}$/D', $instanceKey) !== 1
            || $eventSequence < 1
            || $transitionKey === ''
            || strlen($transitionKey) > 64
            || $subjectRevisionKey === ''
            || strlen($subjectRevisionKey) > 160) {
            throw WorkflowException::definitionInvalid();
        }
        foreach ($notificationIntents as $intent) {
            if (!$intent instanceof WorkflowNotificationIntent) {
                throw WorkflowException::definitionInvalid();
            }
        }
        foreach ($taskIntents as $intent) {
            if (!$intent instanceof WorkflowTaskIntent) {
                throw WorkflowException::definitionInvalid();
            }
        }
        usort($notificationIntents, static fn(WorkflowNotificationIntent $left, WorkflowNotificationIntent $right): int => [$left->templateKey, $left->recipientRule] <=> [$right->templateKey, $right->recipientRule]);
        usort($taskIntents, static fn(WorkflowTaskIntent $left, WorkflowTaskIntent $right): int => strcmp($left->taskType, $right->taskType));
        $this->notificationIntents = array_values($notificationIntents);
        $this->taskIntents = array_values($taskIntents);
    }

    /** @var list<WorkflowNotificationIntent> */
    public array $notificationIntents;

    /** @var list<WorkflowTaskIntent> */
    public array $taskIntents;

    public function childKey(string $kind, int $index): string
    {
        if (!in_array($kind, ['task', 'notification'], true) || $index < 0) {
            throw WorkflowException::definitionInvalid();
        }

        return "wf:{$this->instanceKey}:{$this->eventSequence}:{$kind}:{$index}";
    }

    public function requestHash(WorkflowNotificationIntent|WorkflowTaskIntent $intent): string
    {
        try {
            $json = json_encode($intent->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw WorkflowException::definitionInvalid();
        }

        return hash('sha256', $json);
    }
}
