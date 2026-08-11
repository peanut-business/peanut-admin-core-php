<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Definition;

use JsonException;
use PeanutAdmin\Workflow\Application\WorkflowException;

final readonly class WorkflowGraph
{
    private const ROOT_KEYS = [
        'contract_version',
        'subject_resource_key',
        'subject_read_operation',
        'subject_start_operation',
        'start_permission_keys',
        'nodes',
        'transitions',
    ];

    private const NODE_KEYS = ['key', 'type', 'completion_policy', 'assignments'];
    private const ASSIGNMENT_KEYS = ['kind', 'key'];
    private const TRANSITION_KEYS = [
        'key', 'from', 'to', 'operation', 'action_kind', 'permission_keys',
        'human_required', 'return_edge', 'max_traversals',
        'notification_intent', 'task_intent',
    ];

    /**
     * @param non-empty-list<string> $startPermissionKeys
     * @param non-empty-list<WorkflowNode> $nodes
     * @param non-empty-list<WorkflowTransition> $transitions
     */
    private function __construct(
        public string $subjectResourceKey,
        public string $subjectReadOperation,
        public string $subjectStartOperation,
        public array $startPermissionKeys,
        public array $nodes,
        public array $transitions,
        public string $canonicalJson,
        public string $sha256,
    ) {}

    /** @param array<string, mixed> $graph */
    public static function fromArray(array $graph): self
    {
        self::assertExactKeys($graph, self::ROOT_KEYS);
        if (($graph['contract_version'] ?? null) !== 1) {
            throw WorkflowException::definitionInvalid('Workflow contract_version must be 1.');
        }
        $subjectResourceKey = self::identifier($graph['subject_resource_key'] ?? null, 64, 'subject resource');
        $subjectReadOperation = self::identifier($graph['subject_read_operation'] ?? null, 64, 'subject read operation');
        $subjectStartOperation = self::identifier($graph['subject_start_operation'] ?? null, 64, 'subject start operation');
        $startPermissionKeys = self::permissionKeys($graph['start_permission_keys'] ?? null);

        if (!is_array($graph['nodes']) || !array_is_list($graph['nodes']) || $graph['nodes'] === []) {
            throw WorkflowException::definitionInvalid('Workflow nodes must be a non-empty list.');
        }
        $nodes = [];
        $nodeMap = [];
        $startCount = 0;
        $terminalCount = 0;
        $workNodeCount = 0;
        foreach ($graph['nodes'] as $input) {
            if (!is_array($input) || array_is_list($input)) {
                throw WorkflowException::definitionInvalid('Every workflow node must be an object.');
            }
            $node = self::parseNode($input);
            if (isset($nodeMap[$node->key])) {
                throw WorkflowException::definitionInvalid('Workflow node keys must be unique.');
            }
            $nodeMap[$node->key] = $node;
            $nodes[] = $node;
            $startCount += $node->type === 'start' ? 1 : 0;
            $terminalCount += $node->type === 'terminal' ? 1 : 0;
            $workNodeCount += in_array($node->type, ['review', 'action'], true) ? 1 : 0;
        }
        if ($startCount !== 1 || $terminalCount < 1 || $workNodeCount < 1) {
            throw WorkflowException::definitionInvalid('Workflow requires one start, work nodes, and terminal nodes.');
        }

        if (!is_array($graph['transitions']) || !array_is_list($graph['transitions']) || $graph['transitions'] === []) {
            throw WorkflowException::definitionInvalid('Workflow transitions must be a non-empty list.');
        }
        $transitions = [];
        $transitionKeys = [];
        $outgoing = [];
        foreach ($graph['transitions'] as $input) {
            if (!is_array($input) || array_is_list($input)) {
                throw WorkflowException::definitionInvalid('Every workflow transition must be an object.');
            }
            $transition = self::parseTransition($input, $nodeMap);
            if (isset($transitionKeys[$transition->key])) {
                throw WorkflowException::definitionInvalid('Workflow transition keys must be unique.');
            }
            $transitionKeys[$transition->key] = true;
            $transitions[] = $transition;
            $outgoing[$transition->from][] = $transition;
        }

        $start = null;
        foreach ($nodes as $node) {
            $edges = $outgoing[$node->key] ?? [];
            if ($node->type === 'start') {
                $start = $node;
                if (count($edges) !== 1 || $edges[0]->humanRequired) {
                    throw WorkflowException::definitionInvalid('The start node requires exactly one non-human transition.');
                }
            }
            if ($node->type === 'terminal' && $edges !== []) {
                throw WorkflowException::definitionInvalid('Terminal nodes cannot have outgoing transitions.');
            }
        }
        if (!$start instanceof WorkflowNode) {
            throw WorkflowException::definitionInvalid();
        }
        self::assertReachable($start->key, $nodes, $outgoing);
        self::assertReturnEdges($nodes, $transitions);

        usort($nodes, static fn(WorkflowNode $left, WorkflowNode $right): int => strcmp($left->key, $right->key));
        usort($transitions, static fn(WorkflowTransition $left, WorkflowTransition $right): int => strcmp($left->key, $right->key));
        sort($startPermissionKeys, SORT_STRING);
        $canonical = [
            'contract_version' => 1,
            'subject_resource_key' => $subjectResourceKey,
            'subject_read_operation' => $subjectReadOperation,
            'subject_start_operation' => $subjectStartOperation,
            'start_permission_keys' => $startPermissionKeys,
            'nodes' => array_map(static fn(WorkflowNode $node): array => $node->toArray(), $nodes),
            'transitions' => array_map(static fn(WorkflowTransition $transition): array => $transition->toArray(), $transitions),
        ];
        try {
            $json = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw WorkflowException::definitionInvalid('Workflow graph cannot be encoded.');
        }

        return new self(
            $subjectResourceKey,
            $subjectReadOperation,
            $subjectStartOperation,
            $startPermissionKeys,
            $nodes,
            $transitions,
            $json,
            hash('sha256', $json),
        );
    }

    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw WorkflowException::internal();
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw WorkflowException::internal();
        }
        try {
            $graph = self::fromArray($decoded);
        } catch (WorkflowException) {
            throw WorkflowException::internal();
        }

        return $graph;
    }

    public function node(string $key): WorkflowNode
    {
        foreach ($this->nodes as $node) {
            if (hash_equals($node->key, $key)) {
                return $node;
            }
        }

        throw WorkflowException::transitionUnavailable();
    }

    public function startNode(): WorkflowNode
    {
        foreach ($this->nodes as $node) {
            if ($node->type === 'start') {
                return $node;
            }
        }

        throw WorkflowException::internal();
    }

    public function transition(string $key, string $from): WorkflowTransition
    {
        foreach ($this->transitions as $transition) {
            if (hash_equals($transition->key, $key) && hash_equals($transition->from, $from)) {
                return $transition;
            }
        }

        throw WorkflowException::transitionUnavailable();
    }

    /** @return non-empty-list<WorkflowTransition> */
    public function outgoing(string $nodeKey): array
    {
        $edges = array_values(array_filter(
            $this->transitions,
            static fn(WorkflowTransition $transition): bool => hash_equals($transition->from, $nodeKey),
        ));
        if ($edges === []) {
            throw WorkflowException::transitionUnavailable();
        }

        return $edges;
    }

    /** @param array<string, mixed> $input */
    private static function parseNode(array $input): WorkflowNode
    {
        self::assertExactKeys($input, self::NODE_KEYS);
        $key = self::identifier($input['key'] ?? null, 64, 'node');
        $type = $input['type'] ?? null;
        if (!is_string($type) || !in_array($type, ['start', 'review', 'action', 'terminal'], true)) {
            throw WorkflowException::definitionInvalid('Workflow node type is invalid.');
        }
        if (!is_array($input['assignments']) || !array_is_list($input['assignments'])) {
            throw WorkflowException::definitionInvalid('Workflow node assignments must be a list.');
        }
        $completion = $input['completion_policy'];
        if ($type === 'review') {
            if (!is_string($completion) || !in_array($completion, ['any', 'all'], true) || $input['assignments'] === []) {
                throw WorkflowException::definitionInvalid('Review nodes require a completion policy and assignments.');
            }
        } elseif ($completion !== null || $input['assignments'] !== []) {
            throw WorkflowException::definitionInvalid('Only review nodes may declare assignments.');
        }
        $assignments = [];
        $seen = [];
        foreach ($input['assignments'] as $rule) {
            if (!is_array($rule) || array_is_list($rule)) {
                throw WorkflowException::definitionInvalid('Workflow assignment rule is invalid.');
            }
            self::assertExactKeys($rule, self::ASSIGNMENT_KEYS);
            $kind = $rule['kind'] ?? null;
            if (!is_string($kind) || !in_array($kind, ['member', 'role', 'department', 'initiator', 'previous_actor'], true)) {
                throw WorkflowException::definitionInvalid('Workflow assignment kind is invalid.');
            }
            $ruleKey = $rule['key'] ?? null;
            if ($kind === 'member') {
                if (!is_string($ruleKey)
                    || preg_match('/^[1-9][0-9]*$/D', $ruleKey) !== 1
                    || strlen($ruleKey) > strlen((string) PHP_INT_MAX)
                    || (strlen($ruleKey) === strlen((string) PHP_INT_MAX) && strcmp($ruleKey, (string) PHP_INT_MAX) > 0)) {
                    throw WorkflowException::definitionInvalid('Member assignment key is invalid.');
                }
            } elseif (in_array($kind, ['role', 'department'], true)) {
                $ruleKey = self::identifier($ruleKey, 64, 'assignment');
            } elseif ($ruleKey !== null) {
                throw WorkflowException::definitionInvalid('Contextual assignment keys must be null.');
            }
            $identity = $kind . ':' . ($ruleKey ?? '');
            if (isset($seen[$identity])) {
                throw WorkflowException::definitionInvalid('Workflow assignments must be unique.');
            }
            $seen[$identity] = true;
            $assignments[] = ['kind' => $kind, 'key' => $ruleKey];
        }
        usort($assignments, static fn(array $left, array $right): int => [$left['kind'], $left['key'] ?? ''] <=> [$right['kind'], $right['key'] ?? '']);

        return new WorkflowNode($key, $type, $completion, $assignments);
    }

    /** @param array<string, mixed> $input @param array<string, WorkflowNode> $nodes */
    private static function parseTransition(array $input, array $nodes): WorkflowTransition
    {
        self::assertExactKeys($input, self::TRANSITION_KEYS);
        $key = self::identifier($input['key'] ?? null, 64, 'transition');
        $from = self::identifier($input['from'] ?? null, 64, 'transition source');
        $to = self::identifier($input['to'] ?? null, 64, 'transition target');
        if (!isset($nodes[$from], $nodes[$to]) || hash_equals($from, $to)) {
            throw WorkflowException::definitionInvalid('Workflow transition endpoints are invalid.');
        }
        $operation = self::identifier($input['operation'] ?? null, 64, 'transition operation');
        $action = $input['action_kind'] ?? null;
        if (!is_string($action) || !in_array($action, ['advance', 'approve', 'reject', 'return', 'withdraw', 'automate'], true)) {
            throw WorkflowException::definitionInvalid('Workflow transition action is invalid.');
        }
        $permissionKeys = self::permissionKeys($input['permission_keys'] ?? null);
        if (!is_bool($input['human_required']) || !is_bool($input['return_edge'])) {
            throw WorkflowException::definitionInvalid('Workflow transition flags are invalid.');
        }
        if ($input['human_required'] && $nodes[$from]->type !== 'review') {
            throw WorkflowException::definitionInvalid('Human transitions may leave only review nodes.');
        }
        if (in_array($action, ['approve', 'reject'], true) && !$input['human_required']) {
            throw WorkflowException::definitionInvalid('Approval decisions must require a human work item.');
        }
        if ($action === 'automate' && ($nodes[$from]->type !== 'action' || $input['human_required'])) {
            throw WorkflowException::definitionInvalid('Automation transitions require a non-human action node.');
        }
        $maxTraversals = $input['max_traversals'];
        if ($input['return_edge']) {
            if (!is_int($maxTraversals) || $maxTraversals < 1 || $maxTraversals > 100) {
                throw WorkflowException::definitionInvalid('Return transitions require a bounded traversal count.');
            }
        } elseif ($maxTraversals !== null) {
            throw WorkflowException::definitionInvalid('Only return transitions may declare max_traversals.');
        }

        $notification = $input['notification_intent'];
        if ($notification !== null) {
            if (!is_array($notification) || array_is_list($notification)) {
                throw WorkflowException::definitionInvalid('Notification intent is invalid.');
            }
            self::assertExactKeys($notification, ['template_key', 'recipient_rule']);
            $notification = [
                'template_key' => self::identifier($notification['template_key'] ?? null, 64, 'notification template'),
                'recipient_rule' => $notification['recipient_rule'] ?? null,
            ];
            if (!is_string($notification['recipient_rule'])
                || !in_array($notification['recipient_rule'], ['next_assignees', 'initiator', 'actor'], true)) {
                throw WorkflowException::definitionInvalid('Notification recipient rule is invalid.');
            }
        }
        $task = $input['task_intent'];
        if ($task !== null) {
            if (!is_array($task) || array_is_list($task)) {
                throw WorkflowException::definitionInvalid('Task intent is invalid.');
            }
            self::assertExactKeys($task, ['task_type']);
            $task = ['task_type' => self::identifier($task['task_type'] ?? null, 64, 'task type')];
        }

        return new WorkflowTransition(
            $key,
            $from,
            $to,
            $operation,
            $action,
            $permissionKeys,
            $input['human_required'],
            $input['return_edge'],
            $maxTraversals,
            $notification,
            $task,
        );
    }

    /** @param mixed $value @return non-empty-list<string> */
    private static function permissionKeys(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw WorkflowException::definitionInvalid('Permission keys must be a non-empty list.');
        }
        $permissions = [];
        foreach ($value as $permission) {
            if (!is_string($permission)
                || strlen($permission) < 3
                || strlen($permission) > 160
                || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $permission) !== 1) {
                throw WorkflowException::definitionInvalid('Workflow permission key is invalid.');
            }
            $permissions[$permission] = true;
        }
        $keys = array_keys($permissions);
        sort($keys, SORT_STRING);

        return $keys;
    }

    private static function identifier(mixed $value, int $maximum, string $label): string
    {
        if (!is_string($value)
            || strlen($value) < 1
            || strlen($value) > $maximum
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $value) !== 1) {
            throw WorkflowException::definitionInvalid("Workflow {$label} key is invalid.");
        }

        return $value;
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw WorkflowException::definitionInvalid('Workflow graph object keys are not canonical.');
        }
    }

    /** @param list<WorkflowNode> $nodes @param array<string, list<WorkflowTransition>> $outgoing */
    private static function assertReachable(string $start, array $nodes, array $outgoing): void
    {
        $visited = [];
        $pending = [$start];
        while ($pending !== []) {
            $key = array_pop($pending);
            if (isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;
            foreach ($outgoing[$key] ?? [] as $edge) {
                $pending[] = $edge->to;
            }
        }
        if (count($visited) !== count($nodes)) {
            throw WorkflowException::definitionInvalid('Workflow contains an unreachable node.');
        }
    }

    /** @param list<WorkflowNode> $nodes @param list<WorkflowTransition> $transitions */
    private static function assertReturnEdges(array $nodes, array $transitions): void
    {
        $forward = [];
        foreach ($transitions as $transition) {
            if (!$transition->returnEdge) {
                $forward[$transition->from][] = $transition->to;
            }
        }
        $state = [];
        $visit = function (string $node) use (&$visit, &$state, $forward): void {
            $state[$node] = 1;
            foreach ($forward[$node] ?? [] as $target) {
                if (($state[$target] ?? 0) === 1) {
                    throw WorkflowException::definitionInvalid('Workflow cycles must use declared return transitions.');
                }
                if (($state[$target] ?? 0) === 0) {
                    $visit($target);
                }
            }
            $state[$node] = 2;
        };
        foreach ($nodes as $node) {
            if (($state[$node->key] ?? 0) === 0) {
                $visit($node->key);
            }
        }
        foreach ($transitions as $transition) {
            if (!$transition->returnEdge || !self::pathExists($transition->to, $transition->from, $forward)) {
                if ($transition->returnEdge) {
                    throw WorkflowException::definitionInvalid('A return transition must target an earlier workflow path.');
                }
            }
        }
    }

    /** @param array<string, list<string>> $edges */
    private static function pathExists(string $from, string $to, array $edges): bool
    {
        $seen = [];
        $pending = [$from];
        while ($pending !== []) {
            $node = array_pop($pending);
            if (hash_equals($node, $to)) {
                return true;
            }
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            foreach ($edges[$node] ?? [] as $target) {
                $pending[] = $target;
            }
        }

        return false;
    }
}
