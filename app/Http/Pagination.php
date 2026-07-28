<?php

declare(strict_types=1);

namespace App\Http;

final readonly class Pagination
{
    public function __construct(
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    public function totalPages(): int
    {
        if ($this->perPage < 1) {
            return 0;
        }

        return (int) ceil($this->total / $this->perPage);
    }

    /**
     * @return array{page: int, per_page: int, total: int, total_pages: int}
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'total_pages' => $this->totalPages(),
        ];
    }
}
