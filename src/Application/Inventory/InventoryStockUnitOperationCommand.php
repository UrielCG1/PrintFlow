<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Enum\Inventory\InventoryStockUnitOperationReason;
use App\Enum\Inventory\InventoryStockUnitOperationType;

/** Input for a controlled warehouse condition action identified by the unit QR. */
final readonly class InventoryStockUnitOperationCommand
{
    public function __construct(
        public string $qrPayload,
        public InventoryStockUnitOperationType $type,
        public InventoryStockUnitOperationReason $reason,
        public string $note,
        public ?string $reference = null,
    ) {
        $isSupported = match ($this->type) {
            InventoryStockUnitOperationType::BLOCK => $this->reason->canBlock(),
            InventoryStockUnitOperationType::RELEASE => $this->reason->canRelease(),
            InventoryStockUnitOperationType::DISPOSE => $this->reason->canDispose(),
        };
        if (!$isSupported) {
            throw new \InvalidArgumentException('El motivo indicado no corresponde con la operación de bodega seleccionada.');
        }
    }
}
