<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Inventory\InventoryStockUnit;
use App\Entity\Production\ProductionPrintedOutput;

final readonly class PrintingCompletionResult
{
    public function __construct(
        public ?ProductionPrintedOutput $output,
        public ?InventoryStockUnit $surplusStockUnit,
    ) {
    }
}
