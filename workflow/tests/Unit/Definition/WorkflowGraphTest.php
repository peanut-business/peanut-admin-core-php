<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Tests\Unit\Definition;

use PeanutAdmin\Workflow\Application\WorkflowException;
use PeanutAdmin\Workflow\Definition\WorkflowGraph;
use PHPUnit\Framework\TestCase;

final class WorkflowGraphTest extends TestCase
{
    public function testCanonicalizesAProductNeutralAnyReviewGraph(): void
    {
        $graph = WorkflowGraph::fromArray(self::validGraph());

        self::assertSame('subject.item', $graph->subjectResourceKey);
        self::assertSame('start', $graph->startNode()->key);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $graph->sha256);
        self::assertSame($graph->sha256, WorkflowGraph::fromJson($graph->canonicalJson)->sha256);
        self::assertSame('review', $graph->transition('submit', 'start')->to);
    }

    public function testAcceptsOnlyBoundedDeclaredReturnCycles(): void
    {
        $input = self::validGraph();
        $input['transitions'][] = [
            'key' => 'return-to-review',
            'from' => 'action',
            'to' => 'review',
            'operation' => 'return',
            'action_kind' => 'return',
            'permission_keys' => ['subject.item.return'],
            'human_required' => false,
            'return_edge' => true,
            'max_traversals' => 3,
            'notification_intent' => null,
            'task_intent' => null,
        ];
        self::assertSame(3, WorkflowGraph::fromArray($input)->transition('return-to-review', 'action')->maxTraversals);

        $input['transitions'][3]['return_edge'] = false;
        $input['transitions'][3]['max_traversals'] = null;
        $this->expectException(WorkflowException::class);
        WorkflowGraph::fromArray($input);
    }

    public function testSupportsSequentialReviewsWithoutAFixedStageCount(): void
    {
        $input = self::validGraph();
        $input['nodes'][1]['key'] = 'review-one';
        array_splice($input['nodes'], 2, 0, [[
            'key' => 'review-two',
            'type' => 'review',
            'completion_policy' => 'all',
            'assignments' => [['kind' => 'department', 'key' => 'quality']],
        ]]);
        $input['transitions'][0]['to'] = 'review-one';
        $input['transitions'][1]['key'] = 'approve-one';
        $input['transitions'][1]['from'] = 'review-one';
        $input['transitions'][1]['to'] = 'review-two';
        $input['transitions'][1]['notification_intent'] = null;
        $input['transitions'][1]['task_intent'] = null;
        array_splice($input['transitions'], 2, 0, [[
            'key' => 'approve-two',
            'from' => 'review-two',
            'to' => 'action',
            'operation' => 'approve',
            'action_kind' => 'approve',
            'permission_keys' => ['subject.item.approve'],
            'human_required' => true,
            'return_edge' => false,
            'max_traversals' => null,
            'notification_intent' => ['template_key' => 'workflow.approved', 'recipient_rule' => 'initiator'],
            'task_intent' => ['task_type' => 'workflow.finalize'],
        ]]);

        $graph = WorkflowGraph::fromArray($input);

        self::assertSame('review-one', $graph->transition('submit', 'start')->to);
        self::assertSame('review-two', $graph->transition('approve-one', 'review-one')->to);
        self::assertSame('action', $graph->transition('approve-two', 'review-two')->to);
    }

    public function testRejectsOutOfRangeReturnTraversalBounds(): void
    {
        foreach ([0, 101] as $bound) {
            $input = self::validGraph();
            $input['transitions'][] = [
                'key' => 'return-to-review',
                'from' => 'action',
                'to' => 'review',
                'operation' => 'return',
                'action_kind' => 'return',
                'permission_keys' => ['subject.item.return'],
                'human_required' => false,
                'return_edge' => true,
                'max_traversals' => $bound,
                'notification_intent' => null,
                'task_intent' => null,
            ];
            try {
                WorkflowGraph::fromArray($input);
                self::fail("Return traversal bound {$bound} must fail closed.");
            } catch (WorkflowException $exception) {
                self::assertSame('WORKFLOW_DEFINITION_INVALID', $exception->errorCode);
            }
        }
    }

    public function testRejectsUnknownKeysAndUnreachableNodes(): void
    {
        $input = self::validGraph();
        $input['nodes'][] = [
            'key' => 'orphan',
            'type' => 'terminal',
            'completion_policy' => null,
            'assignments' => [],
        ];
        $input['unknown'] = true;

        try {
            WorkflowGraph::fromArray($input);
            self::fail('Unknown graph keys must fail closed.');
        } catch (WorkflowException $exception) {
            self::assertSame('WORKFLOW_DEFINITION_INVALID', $exception->errorCode);
        }
        unset($input['unknown']);
        $this->expectException(WorkflowException::class);
        WorkflowGraph::fromArray($input);
    }

    public function testRejectsNonHumanApprovalAndRejectionEdges(): void
    {
        foreach (['approve', 'reject'] as $action) {
            $input = self::validGraph();
            $input['transitions'][1]['action_kind'] = $action;
            $input['transitions'][1]['human_required'] = false;
            try {
                WorkflowGraph::fromArray($input);
                self::fail("{$action} must require a human work item.");
            } catch (WorkflowException $exception) {
                self::assertSame('WORKFLOW_DEFINITION_INVALID', $exception->errorCode);
            }
        }
    }

    /** @return array<string, mixed> */
    public static function validGraph(): array
    {
        return [
            'contract_version' => 1,
            'subject_resource_key' => 'subject.item',
            'subject_read_operation' => 'read',
            'subject_start_operation' => 'submit',
            'start_permission_keys' => ['subject.item.submit'],
            'nodes' => [
                ['key' => 'start', 'type' => 'start', 'completion_policy' => null, 'assignments' => []],
                ['key' => 'review', 'type' => 'review', 'completion_policy' => 'any', 'assignments' => [
                    ['kind' => 'role', 'key' => 'reviewer'],
                ]],
                ['key' => 'action', 'type' => 'action', 'completion_policy' => null, 'assignments' => []],
                ['key' => 'done', 'type' => 'terminal', 'completion_policy' => null, 'assignments' => []],
            ],
            'transitions' => [
                [
                    'key' => 'submit', 'from' => 'start', 'to' => 'review', 'operation' => 'submit',
                    'action_kind' => 'advance', 'permission_keys' => ['subject.item.submit'],
                    'human_required' => false, 'return_edge' => false, 'max_traversals' => null,
                    'notification_intent' => null, 'task_intent' => null,
                ],
                [
                    'key' => 'approve', 'from' => 'review', 'to' => 'action', 'operation' => 'approve',
                    'action_kind' => 'approve', 'permission_keys' => ['subject.item.approve'],
                    'human_required' => true, 'return_edge' => false, 'max_traversals' => null,
                    'notification_intent' => ['template_key' => 'workflow.approved', 'recipient_rule' => 'initiator'],
                    'task_intent' => ['task_type' => 'workflow.finalize'],
                ],
                [
                    'key' => 'finish', 'from' => 'action', 'to' => 'done', 'operation' => 'finalize',
                    'action_kind' => 'automate', 'permission_keys' => ['subject.item.finalize'],
                    'human_required' => false, 'return_edge' => false, 'max_traversals' => null,
                    'notification_intent' => null, 'task_intent' => null,
                ],
            ],
        ];
    }
}
