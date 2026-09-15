<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Application;

final readonly class IntegrationSecurityPage implements \JsonSerializable
{
    /** @param list<object> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $pageSize,
        public int $total,
    ) {}

    /** @return array{items: list<object>, page: int, page_size: int, total: int} */
    public function jsonSerialize(): array
    {
        return ['items' => $this->items, 'page' => $this->page, 'page_size' => $this->pageSize, 'total' => $this->total];
    }
}
