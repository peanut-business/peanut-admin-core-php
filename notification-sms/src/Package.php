<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms;

final class Package
{
    public const MODULE_KEY = 'peanut.notification-sms';
    public const RESOURCE_KEY = 'peanut.notification-sms';
    public const READ_PERMISSION = 'peanut.notification-sms.read';
    public const MANAGE_PERMISSION = 'peanut.notification-sms.manage';

    private function __construct() {}
}
