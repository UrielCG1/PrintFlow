<?php

declare(strict_types=1);

namespace App\Application\Inventory;

/** @param list<InventoryPurchaseRequestLineInput> $lines */
final readonly class InventoryPurchaseRequestCommand
{
    public function __construct(
        public string $idempotencyKey,
        public array $lines,
        public ?string $notes = null,
    ) {
    }
}
