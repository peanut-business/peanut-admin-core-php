<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform\Bootstrap;

final readonly class PlatformBootstrapResult
{
    public function __construct(
        public int $accountId,
        public int $operatorId,
        public int $roleId,
    ) {}

    /** @return array{account_id: int, operator_id: int, role_id: int} */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'operator_id' => $this->operatorId,
            'role_id' => $this->roleId,
        ];
    }
}
