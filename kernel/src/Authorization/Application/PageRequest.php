<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Application;

final readonly class PageRequest
{
    public function __construct(public int $page = 1, public int $pageSize = 20)
    {
        if ($page < 1) {
            throw AdminAccessException::invalid('PAGE_INVALID', 'Page must be at least 1.');
        }
        if ($pageSize < 1 || $pageSize > 100) {
            throw AdminAccessException::invalid('PAGE_SIZE_INVALID', 'Page size must be between 1 and 100.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }
}
