<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

use think\db\PDOConnection;

final readonly class ThinkPhpTargetSetMembershipProvider implements TargetSetMembershipProvider
{
    public function __construct(private PDOConnection $connection) {}

    public function containsAll(int $tenantId, int $targetSetId, array $targetIds): bool
    {
        $targetIds = array_values(array_unique($targetIds));
        if ($targetIds === []) {
            return true;
        }
        foreach (array_chunk($targetIds, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $row = $this->connection->query(<<<SQL
SELECT COUNT(DISTINCT target_id) AS aggregate
FROM pa_data_permission_target
WHERE tenant_id = ? AND target_set_id = ? AND status = 'active'
  AND target_id IN ({$placeholders})
SQL, [$tenantId, $targetSetId, ...$chunk])[0] ?? null;
            if (!is_array($row) || (int) $row['aggregate'] !== count($chunk)) {
                return false;
            }
        }

        return true;
    }
}
