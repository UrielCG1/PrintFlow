<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Materials\MaterialVariant;
use App\Entity\Users\User;
use App\Enum\Inventory\InventoryStockUnitOperationReason;
use App\Enum\Inventory\InventoryStockUnitOperationType;
use App\Enum\Inventory\InventoryStockUnitStatus;
use App\Repository\Inventory\InventoryStockUnitOperationRepository;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Immutable trace of a controlled condition or withdrawal of one QR unit. */
#[ORM\Entity(repositoryClass: InventoryStockUnitOperationRepository::class)]
#[ORM\Table(name: 'inventory_stock_unit_operations')]
#[ORM\Index(name: 'idx_inventory_stock_operation_unit_occurred', columns: ['stock_unit_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_inventory_stock_operation_variant_occurred', columns: ['material_variant_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_inventory_stock_operation_type_occurred', columns: ['operation_type', 'occurred_at'])]
final class InventoryStockUnitOperation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'stock_unit_id', nullable: false, onDelete: 'RESTRICT')]
    private InventoryStockUnit $stockUnit;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'material_variant_id', nullable: false, onDelete: 'RESTRICT')]
    private MaterialVariant $variant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'responsible_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $responsibleUser;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'inventory_movement_id', nullable: true, onDelete: 'RESTRICT')]
    private ?InventoryMovement $movement = null;

    #[ORM\Column(name: 'operation_type', length: 20, enumType: InventoryStockUnitOperationType::class)]
    private InventoryStockUnitOperationType $type;

    #[ORM\Column(name: 'reason', length: 30, enumType: InventoryStockUnitOperationReason::class)]
    private InventoryStockUnitOperationReason $reason;

    #[ORM\Column(name: 'quantity', type: Types::DECIMAL, precision: 20, scale: 6)]
    private string $quantity;

    #[ORM\Column(name: 'previous_status', length: 20, enumType: InventoryStockUnitStatus::class)]
    private InventoryStockUnitStatus $previousStatus;

    #[ORM\Column(name: 'resulting_status', length: 20, enumType: InventoryStockUnitStatus::class)]
    private InventoryStockUnitStatus $resultingStatus;

    #[ORM\Column(name: 'note', length: 500)]
    private string $note;

    #[ORM\Column(name: 'reference', length: 100, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        InventoryStockUnit $stockUnit,
        User $responsibleUser,
        InventoryStockUnitOperationType $type,
        InventoryStockUnitOperationReason $reason,
        string $quantity,
        InventoryStockUnitStatus $previousStatus,
        InventoryStockUnitStatus $resultingStatus,
        string $note,
        ?string $reference = null,
    ) {
        if (!$this->reasonSupportsType($type, $reason)) {
            throw new \InvalidArgumentException('El motivo indicado no corresponde con la operación de bodega seleccionada.');
        }

        $note = trim($note);
        if ($note === '') {
            throw new \InvalidArgumentException('Indica una observación para conservar la trazabilidad de la operación.');
        }
        if (mb_strlen($note) > 500) {
            throw new \InvalidArgumentException('La observación de la operación no puede superar 500 caracteres.');
        }

        $reference = trim((string) $reference);
        if (mb_strlen($reference) > 100) {
            throw new \InvalidArgumentException('La referencia externa no puede superar 100 caracteres.');
        }
        if ($reason === InventoryStockUnitOperationReason::RETURN_TO_SUPPLIER && $reference === '') {
            throw new \InvalidArgumentException('Indica el folio de remisión, devolución o autorización del proveedor.');
        }

        $this->stockUnit = $stockUnit;
        $this->variant = $stockUnit->getVariant();
        $this->responsibleUser = $responsibleUser;
        $this->type = $type;
        $this->reason = $reason;
        $this->quantity = self::positiveDecimal($quantity);
        $this->previousStatus = $previousStatus;
        $this->resultingStatus = $resultingStatus;
        $this->note = $note;
        $this->reference = $reference !== '' ? $reference : null;
        $this->occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStockUnit(): InventoryStockUnit
    {
        return $this->stockUnit;
    }

    public function getVariant(): MaterialVariant
    {
        return $this->variant;
    }

    public function getResponsibleUser(): User
    {
        return $this->responsibleUser;
    }

    public function getMovement(): ?InventoryMovement
    {
        return $this->movement;
    }

    public function getType(): InventoryStockUnitOperationType
    {
        return $this->type;
    }

    public function getReason(): InventoryStockUnitOperationReason
    {
        return $this->reason;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getPreviousStatus(): InventoryStockUnitStatus
    {
        return $this->previousStatus;
    }

    public function getResultingStatus(): InventoryStockUnitStatus
    {
        return $this->resultingStatus;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function attachMovement(InventoryMovement $movement): void
    {
        if ($this->type !== InventoryStockUnitOperationType::DISPOSE) {
            throw new \LogicException('Solo un retiro físico puede tener un movimiento de inventario.');
        }
        if ($movement->getVariant() !== $this->variant) {
            throw new \DomainException('El movimiento debe pertenecer a la misma variante de material.');
        }
        if ($this->movement !== null) {
            throw new \LogicException('La operación de bodega ya tiene un movimiento asociado.');
        }

        $this->movement = $movement;
    }

    private function reasonSupportsType(
        InventoryStockUnitOperationType $type,
        InventoryStockUnitOperationReason $reason,
    ): bool {
        return match ($type) {
            InventoryStockUnitOperationType::BLOCK => $reason->canBlock(),
            InventoryStockUnitOperationType::RELEASE => $reason->canRelease(),
            InventoryStockUnitOperationType::DISPOSE => $reason->canDispose(),
        };
    }

    private static function positiveDecimal(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('La cantidad de la operación no tiene un formato válido.');
        }

        $quantity = BigDecimal::of($value)->toScale(6);
        if ($quantity->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new \InvalidArgumentException('La cantidad de la operación debe ser mayor que cero.');
        }

        return $quantity->__toString();
    }
}
