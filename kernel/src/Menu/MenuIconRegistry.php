<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Menu;

use PeanutAdmin\Kernel\Authorization\Governance\GovernanceException;

final class MenuIconRegistry
{
    /** @var array<string, true> */
    private array $icons = [];

    /** @param list<string> $icons */
    public function __construct(array $icons)
    {
        foreach ($icons as $icon) {
            if (preg_match('/^[A-Z][A-Za-z0-9]{0,63}$/D', $icon) !== 1 || isset($this->icons[$icon])) {
                throw new GovernanceException('GOVERNANCE_ICON_INVALID', 'The governance icon declaration is invalid.');
            }
            $this->icons[$icon] = true;
        }
    }

    public function require(?string $icon): ?string
    {
        if ($icon === null) {
            return null;
        }
        if (!isset($this->icons[$icon])) {
            throw new GovernanceException('GOVERNANCE_ICON_UNDECLARED', 'The requested menu icon is not declared.');
        }

        return $icon;
    }
}
