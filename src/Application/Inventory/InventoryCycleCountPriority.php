<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryStockUnit;

/** One physical QR unit proposed for the next cycle-count route. */
final readonly class InventoryCycleCountPriority
{
    /** @param list<string> $reasons */
    public function __construct(
        public InventoryStockUnit $stockUnit,
        public int $score,
        public string $level,
        public ?\DateTimeImmutable $lastCountedAt,
        public int $recentActivityCount,
        public int $recentDifferenceCount,
        public array $reasons,
    ) {
    }
}
