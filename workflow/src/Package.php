<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow;

final class Package
{
    public const MODULE_KEY = 'peanut.workflow';
    public const DEFINITION_RESOURCE_KEY = 'peanut.workflow.definition';
    public const DEFINITION_READ_PERMISSION = 'peanut.workflow.definition.read';
    public const DEFINITION_WRITE_PERMISSION = 'peanut.workflow.definition.write';
    public const DEFINITION_PUBLISH_PERMISSION = 'peanut.workflow.definition.publish';
    public const INSTANCE_READ_PERMISSION = 'peanut.workflow.instance.read';
    public const INSTANCE_START_PERMISSION = 'peanut.workflow.instance.start';
    public const INSTANCE_TRANSITION_PERMISSION = 'peanut.workflow.instance.transition';
    public const VERSION = '0.1.0-alpha.5';

    private function __construct() {}
}
