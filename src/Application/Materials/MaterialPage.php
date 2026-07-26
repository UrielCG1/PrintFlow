<?php

namespace App\Application\Materials;

use App\Entity\Materials\Material;

final readonly class MaterialPage
{
    /**
     * @param list<Material> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $currentPage,
        public int $pageCount,
    ) {
    }
}