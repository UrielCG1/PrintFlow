<?php

namespace App\Application\Suppliers;

use App\Entity\Suppliers\Supplier;

final readonly class SupplierPage
{
    /**
     * @param list<Supplier> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $currentPage,
        public int $pageCount,
    ) {
    }
}