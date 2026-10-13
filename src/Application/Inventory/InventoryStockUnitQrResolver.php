<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryStockUnit;
use App\Repository\Inventory\InventoryStockUnitRepository;

/** Resolves a QR to an available physical stock unit. */
final class InventoryStockUnitQrResolver
{
    public function __construct(private readonly InventoryStockUnitRepository $stockUnitRepository)
    {
    }

    public function resolve(string $payload): InventoryStockUnit
    {
        $stockUnit = $this->resolveForCount($payload);
        if (!$stockUnit->isAvailable()) {
            throw new \DomainException('La unidad física escaneada no tiene saldo disponible para usarse en impresión.');
        }

        return $stockUnit;
    }

    /** Resolves a QR for physical counting, including exhausted or blocked units. */
    public function resolveForCount(string $payload): InventoryStockUnit
    {
        $qrPayload = InventoryStockUnitQrPayload::fromScanned($payload);
        $stockUnit = $this->stockUnitRepository->findOneByQrToken($qrPayload->getToken());
        if (!$stockUnit instanceof InventoryStockUnit) {
            throw new \DomainException('La etiqueta QR no corresponde a una unidad física registrada en inventario.');
        }
        if ($stockUnit->getStatus()->value === 'DISPOSED') {
            throw new \DomainException('La unidad física escaneada fue desechada y no puede contarse.');
        }

        return $stockUnit;
    }
}
