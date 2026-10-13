<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Materials\MaterialVariant;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Una partida física recibida; cada una crea exactamente una unidad QR y un movimiento. */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_receipt_lines')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_receipt_line_number', columns: ['receipt_id', 'line_number'])]
#[ORM\UniqueConstraint(name: 'uniq_inventory_receipt_line_stock_unit', columns: ['stock_unit_id'])]
#[ORM\UniqueConstraint(name: 'uniq_inventory_receipt_line_movement', columns: ['inventory_movement_id'])]
class InventoryReceiptLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'receipt_id', nullable: false, onDelete: 'RESTRICT')]
    private InventoryReceipt $receipt;

    #[ORM\Column(name: 'line_number')]
    private int $lineNumber;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'material_variant_id', nullable: false, onDelete: 'RESTRICT')]
    private MaterialVariant $variant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'lot_id', nullable: true, onDelete: 'RESTRICT')]
    private ?InventoryLot $lot;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'stock_unit_id', nullable: false, onDelete: 'RESTRICT')]
    private InventoryStockUnit $stockUnit;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'unit_id', nullable: false, onDelete: 'RESTRICT')]
    private MeasurementUnit $unit;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'purchase_request_line_id', nullable: true, onDelete: 'RESTRICT')]
    private ?InventoryPurchaseRequestLine $purchaseRequestLine = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'inventory_movement_id', nullable: true, onDelete: 'RESTRICT')]
    private ?InventoryMovement $movement = null;

    #[ORM\Column(name: 'received_quantity', type: Types::DECIMAL, precision: 20, scale: 6)]
    private string $receivedQuantity;

    #[ORM\Column(name: 'unit_cost_mxn', type: Types::DECIMAL, precision: 19, scale: 6, nullable: true)]
    private ?string $unitCostMxn;

    public function __construct(
        InventoryReceipt $receipt,
        int $lineNumber,
        MaterialVariant $variant,
        ?InventoryLot $lot,
        InventoryStockUnit $stockUnit,
        string $receivedQuantity,
        ?string $unitCostMxn,
        ?InventoryPurchaseRequestLine $purchaseRequestLine = null,
    ) {
        if ($lineNumber < 1) {
            throw new \InvalidArgumentException('La partida de recepción debe tener un número positivo.');
        }
        if ($lot !== null && $lot->getVariant() !== $variant) {
            throw new \DomainException('El lote de recepción debe pertenecer a la variante recibida.');
        }
        if ($stockUnit->getVariant() !== $variant) {
            throw new \DomainException('La unidad QR debe pertenecer a la variante recibida.');
        }
        if ($purchaseRequestLine !== null && $purchaseRequestLine->getVariant() !== $variant) {
            throw new \DomainException('La solicitud de compra debe corresponder a la misma variante recibida.');
        }

        $quantity = self::positiveDecimal($receivedQuantity, 'La cantidad recibida');
        if ($stockUnit->getInitialQuantity() !== $quantity) {
            throw new \DomainException('La cantidad de la partida debe coincidir con la cantidad inicial de la unidad QR.');
        }

        $this->receipt = $receipt;
        $this->lineNumber = $lineNumber;
        $this->variant = $variant;
        $this->lot = $lot;
        $this->stockUnit = $stockUnit;
        $this->unit = $variant->getInventoryUnit();
        $this->receivedQuantity = $quantity;
        $this->unitCostMxn = self::nullableNonNegativeDecimal($unitCostMxn, 'El costo unitario');
        $this->purchaseRequestLine = $purchaseRequestLine;
    }

    public function getId(): ?int { return $this->id; }
    public function getReceipt(): InventoryReceipt { return $this->receipt; }
    public function getVariant(): MaterialVariant { return $this->variant; }
    public function getLot(): ?InventoryLot { return $this->lot; }
    public function getStockUnit(): InventoryStockUnit { return $this->stockUnit; }
    public function getReceivedQuantity(): string { return $this->receivedQuantity; }
    public function getUnitCostMxn(): ?string { return $this->unitCostMxn; }
    public function getPurchaseRequestLine(): ?InventoryPurchaseRequestLine { return $this->purchaseRequestLine; }

    public function attachMovement(InventoryMovement $movement): void
    {
        if ($movement->getVariant() !== $this->variant || $movement->getStockUnit() !== $this->stockUnit) {
            throw new \DomainException('El movimiento debe corresponder a la partida y unidad física recibidas.');
        }
        $this->movement = $movement;
    }

    private static function positiveDecimal(string $value, string $field): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $value) !== 1 || BigDecimal::of($value)->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new \InvalidArgumentException($field.' debe ser mayor que cero y tener máximo seis decimales.');
        }

        return BigDecimal::of($value)->toScale(6)->__toString();
    }

    private static function nullableNonNegativeDecimal(?string $value, string $field): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^(?:0|[1-9]\d{0,12})(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field.' no tiene un formato válido.');
        }

        return BigDecimal::of($value)->toScale(6)->__toString();
    }
}
