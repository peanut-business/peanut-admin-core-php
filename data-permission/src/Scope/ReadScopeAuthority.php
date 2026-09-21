<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Scope;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Implemented by the IAM owner; callers cannot construct authority from request grants. */
interface ReadScopeAuthority
{
    /**
     * @param list<int> $requestedSourceTenantIds
     * @param list<string> $requestedFields 明确请求的输出字段；空值保留共同投影的兼容语义。
     * 指定字段时，授权者必须同时收紧到获准这些字段的对象，不能直接扩大字段并集。
     */
    public function authorize(
        TenantContext $actor,
        string $capability,
        string $action,
        array $requestedSourceTenantIds = [],
        array $requestedFields = [],
    ): AuthorizedReadScope;

    /** Recheck module, permission and grant revisions before a batch or result delivery. */
    public function assertCurrent(AuthorizedReadScope $scope): void;
}
