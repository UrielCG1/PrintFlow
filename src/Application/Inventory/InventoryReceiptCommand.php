<?php

declare(strict_types=1);

namespace App\Application\Inventory;

/** @param list<InventoryReceiptLineInput> $lines */
final readonly class InventoryReceiptCommand
{
    public function __construct(
        public string $idempotencyKey,
        public array $lines,
        public ?string $reference = null,
        public ?string $notes = null,
        public bool $isProvisional = false,
    ) {
    }
}
