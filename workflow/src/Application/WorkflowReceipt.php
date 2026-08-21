<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Application;

final readonly class WorkflowReceipt
{
    private const OPERATIONS = [
        'workflow.save-draft',
        'workflow.publish-definition',
        'workflow.retire-definition',
        'workflow.start-instance',
        'workflow.apply-transition',
        'workflow.apply-automation',
    ];

    /** @param list<string> $workItemKeys */
    public function __construct(
        public string $operation,
        public ?int $definitionId,
        public ?int $definitionVersion,
        public ?string $instanceKey,
        public ?string $instanceStatus,
        public ?string $currentNodeKey,
        public ?int $instanceRevision,
        public ?int $eventSequence,
        array $workItemKeys,
    ) {
        sort($workItemKeys, SORT_STRING);
        $this->workItemKeys = array_values(array_unique($workItemKeys, SORT_STRING));
    }

    /** @var list<string> */
    public array $workItemKeys;

    /** @return array<string, int|string|list<string>|null> */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'definition_id' => $this->definitionId,
            'definition_version' => $this->definitionVersion,
            'instance_key' => $this->instanceKey,
            'instance_status' => $this->instanceStatus,
            'current_node_key' => $this->currentNodeKey,
            'instance_revision' => $this->instanceRevision,
            'event_sequence' => $this->eventSequence,
            'work_item_keys' => $this->workItemKeys,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value, string $expectedOperation): self
    {
        $expected = [
            'operation', 'definition_id', 'definition_version', 'instance_key',
            'instance_status', 'current_node_key', 'instance_revision',
            'event_sequence', 'work_item_keys',
        ];
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected
            || !is_string($value['operation'])
            || !is_array($value['work_item_keys'])
            || !in_array($expectedOperation, self::OPERATIONS, true)
            || !in_array($value['operation'], self::OPERATIONS, true)
            || !hash_equals($expectedOperation, $value['operation'])
        ) {
            throw WorkflowException::internal();
        }
        foreach ($value['work_item_keys'] as $key) {
            if (!is_string($key) || preg_match('/^work_[0-9a-f]{32}$/D', $key) !== 1) {
                throw WorkflowException::internal();
            }
        }
        foreach (['definition_id', 'definition_version', 'instance_revision', 'event_sequence'] as $field) {
            if ($value[$field] !== null && (!is_int($value[$field]) || $value[$field] < 1)) {
                throw WorkflowException::internal();
            }
        }
        foreach (['instance_key', 'instance_status', 'current_node_key'] as $field) {
            if ($value[$field] !== null && !is_string($value[$field])) {
                throw WorkflowException::internal();
            }
        }
        $keys = array_values($value['work_item_keys']);
        $canonicalKeys = $keys;
        sort($canonicalKeys, SORT_STRING);
        if ($keys !== $canonicalKeys || count($keys) !== count(array_unique($keys, SORT_STRING))) {
            throw WorkflowException::internal();
        }
        $definitionOperation = in_array($value['operation'], [
            'workflow.save-draft',
            'workflow.publish-definition',
            'workflow.retire-definition',
        ], true);
        if (!is_int($value['definition_id']) || $value['definition_id'] < 1) {
            throw WorkflowException::internal();
        }
        if ($definitionOperation) {
            if (($value['operation'] === 'workflow.save-draft'
                    && $value['definition_version'] !== null
                    && (!is_int($value['definition_version']) || $value['definition_version'] < 1))
                || ($value['operation'] !== 'workflow.save-draft'
                    && (!is_int($value['definition_version']) || $value['definition_version'] < 1))) {
                throw WorkflowException::internal();
            }
            if ($value['instance_key'] !== null
                || $value['instance_status'] !== null
                || $value['current_node_key'] !== null
                || $value['instance_revision'] !== null
                || $value['event_sequence'] !== null
                || $keys !== []) {
                throw WorkflowException::internal();
            }
        } elseif (!is_int($value['definition_version'])
            || $value['definition_version'] < 1
            || !is_string($value['instance_key'])
            || preg_match('/^instance_[0-9a-f]{32}$/D', $value['instance_key']) !== 1
            || !is_string($value['instance_status'])
            || !in_array($value['instance_status'], ['active', 'completed', 'cancelled'], true)
            || !is_string($value['current_node_key'])
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $value['current_node_key']) !== 1
            || !is_int($value['instance_revision'])
            || $value['instance_revision'] < 1
            || !is_int($value['event_sequence'])
            || $value['event_sequence'] < 1) {
            throw WorkflowException::internal();
        }
        if ($value['operation'] === 'workflow.start-instance'
            && ($value['instance_status'] !== 'active'
                || $value['instance_revision'] !== 1
                || $value['event_sequence'] !== 1)) {
            throw WorkflowException::internal();
        }

        return new self(
            $value['operation'],
            $value['definition_id'],
            $value['definition_version'],
            $value['instance_key'],
            $value['instance_status'],
            $value['current_node_key'],
            $value['instance_revision'],
            $value['event_sequence'],
            $keys,
        );
    }
}
