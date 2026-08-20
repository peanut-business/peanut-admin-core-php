<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Sms;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

interface NoticeSmsSender
{
    /** @return array{success:bool,provider:string,error:string,result:array<string,mixed>} */
    public function send(
        TenantContext|TenantSystemContext $context,
        string $mobile,
        string $templateId,
        array $variables,
        ?callable $beforeSend = null,
    ): array;
}
