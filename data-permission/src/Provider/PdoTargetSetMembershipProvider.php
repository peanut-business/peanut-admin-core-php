<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

use PDO;

final readonly class PdoTargetSetMembershipProvider implements TargetSetMembershipProvider
{
    public function __construct(private PDO $pdo) {}

    public function containsAll(int $tenantId, int $targetSetId, array $targetIds): bool
    {
        $targetIds = array_values(array_unique($targetIds));
        if ($targetIds === []) {
            return true;
        }
        foreach (array_chunk($targetIds, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(<<<SQL
SELECT COUNT(DISTINCT target_id)
FROM pa_data_permission_target
WHERE tenant_id = ? AND target_set_id = ? AND status = 'active'
  AND target_id IN ({$placeholders})
SQL);
            $statement->execute([$tenantId, $targetSetId, ...$chunk]);
            if ((int) $statement->fetchColumn() !== count($chunk)) {
                return false;
            }
        }

        return true;
    }
}
