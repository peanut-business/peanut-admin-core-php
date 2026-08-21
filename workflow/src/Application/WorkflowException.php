<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Application;

use RuntimeException;

final class WorkflowException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function definitionInvalid(string $message = 'The workflow definition is invalid.'): self
    {
        return new self('WORKFLOW_DEFINITION_INVALID', 422, $message);
    }

    public static function preconditionRequired(): self
    {
        return new self('WORKFLOW_PRECONDITION_REQUIRED', 428, 'A workflow revision precondition is required.');
    }

    public static function definitionConflict(): self
    {
        return new self('WORKFLOW_DEFINITION_CONFLICT', 409, 'The workflow definition revision has changed.');
    }

    public static function definitionRetired(): self
    {
        return new self('WORKFLOW_DEFINITION_RETIRED', 409, 'The workflow definition is retired.');
    }

    public static function instanceConflict(): self
    {
        return new self('WORKFLOW_INSTANCE_CONFLICT', 409, 'The workflow instance revision has changed.');
    }

    public static function transitionUnavailable(): self
    {
        return new self('WORKFLOW_TRANSITION_UNAVAILABLE', 409, 'The workflow transition is unavailable.');
    }

    public static function assignmentDenied(): self
    {
        return new self('WORKFLOW_ASSIGNMENT_DENIED', 403, 'The workflow assignment does not permit this decision.');
    }

    public static function subjectNotFound(): self
    {
        return new self('WORKFLOW_SUBJECT_NOT_FOUND', 404, 'The workflow subject is unavailable.');
    }

    public static function subjectRevisionConflict(): self
    {
        return new self('WORKFLOW_SUBJECT_REVISION_CONFLICT', 409, 'The workflow subject revision has changed.');
    }

    public static function attachmentUnavailable(): self
    {
        return new self('WORKFLOW_ATTACHMENT_UNAVAILABLE', 404, 'A workflow attachment is unavailable.');
    }

    public static function providerUnavailable(): self
    {
        return new self('WORKFLOW_PROVIDER_UNAVAILABLE', 503, 'A required workflow provider is unavailable.');
    }

    public static function internal(): self
    {
        return new self('INTERNAL_ERROR', 500, 'The workflow operation could not be completed.');
    }
}
