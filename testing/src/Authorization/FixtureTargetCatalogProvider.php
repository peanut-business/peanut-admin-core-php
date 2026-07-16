<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Authorization;

use PDO;
use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Context\AuthorizationContext;
use PeanutAdmin\DataPermission\Policy\EffectivePolicySet;
use PeanutAdmin\DataPermission\Target\ResourceTargetCatalogProvider;
use PeanutAdmin\DataPermission\Target\TargetCatalogQuery;
use PeanutAdmin\DataPermission\Target\TargetOptionPage;

final readonly class FixtureTargetCatalogProvider implements ResourceTargetCatalogProvider
{
    public function __construct(private PDO $pdo) {}

    public function searchAllowedTargets(
        AuthorizationContext $context,
        ResourceOperation $operation,
        TargetCatalogQuery $query,
        EffectivePolicySet $policies,
    ): TargetOptionPage {
        $offset = ($query->page - 1) * $query->pageSize;
        $count = $this->pdo->prepare(<<<'SQL'
SELECT COUNT(*)
FROM fixture_target_visibility visibility
JOIN fixture_project project
  ON project.tenant_id = visibility.tenant_id AND project.id = visibility.target_id
WHERE visibility.tenant_id = :tenant_id AND visibility.member_id = :member_id
  AND project.name LIKE :search
SQL);
        $count->execute([
            'tenant_id' => $context->tenant->tenantId,
            'member_id' => $context->tenant->memberId,
            'search' => '%' . $query->search . '%',
        ]);
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT project.id, project.name
FROM fixture_target_visibility visibility
JOIN fixture_project project
  ON project.tenant_id = visibility.tenant_id AND project.id = visibility.target_id
WHERE visibility.tenant_id = :tenant_id AND visibility.member_id = :member_id
  AND project.name LIKE :search
ORDER BY project.id
LIMIT :limit OFFSET :offset
SQL);
        $statement->bindValue(':tenant_id', $context->tenant->tenantId, PDO::PARAM_INT);
        $statement->bindValue(':member_id', $context->tenant->memberId, PDO::PARAM_INT);
        $statement->bindValue(':search', '%' . $query->search . '%');
        $statement->bindValue(':limit', $query->pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $items = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $items[] = ['id' => (string) $row['id'], 'label' => (string) $row['name']];
        }

        return new TargetOptionPage($items, (int) $count->fetchColumn());
    }
}
