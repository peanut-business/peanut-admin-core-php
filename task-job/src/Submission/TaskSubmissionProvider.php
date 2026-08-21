<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Submission;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface TaskSubmissionProvider
{
    public function taskType(): string;

    public function resourceKey(): string;

    public function operation(): string;

    /** @param array<string, mixed> $input */
    public function build(AuthorizedOperationContext $context, array $input): TaskSubmission;
}
