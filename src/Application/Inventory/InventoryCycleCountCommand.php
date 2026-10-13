<?php

declare(strict_types=1);

namespace App\Application\Inventory;

/** Input captured when a worker scans one physical inventory unit to count it. */
final readonly class InventoryCycleCountCommand
{
    public function __construct(
        public string $idempotencyKey,
        public string $qrPayload,
        public string $countedQuantity,
        public ?string $note = null,
    ) {
    }
}
