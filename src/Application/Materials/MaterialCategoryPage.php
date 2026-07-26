<?php

namespace App\Application\Materials;

use App\Entity\Materials\MaterialCategory;

final readonly class MaterialCategoryPage
{
    /**
     * @param list<MaterialCategory> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $currentPage,
        public int $pageCount,
    ) {
    }
}