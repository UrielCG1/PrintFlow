<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Common\Timestampable;
use App\Entity\Materials\MaterialVariant;
use App\Enum\Inventory\InventoryStockUnitKind;
use App\Enum\Inventory\InventoryStockUnitStatus;
use App\Repository\Inventory\InventoryStockUnitRepository;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Objeto físico identificable: rollo, envase, paquete o sobrante recuperable.
 * El saldo de esta unidad complementa el libro de movimientos y se actualiza
 * únicamente dentro de los casos transaccionales de inventario.
 */
#[ORM\Entity(repositoryClass: InventoryStockUnitRepository::class)]
#[ORM\Table(name: 'inventory_stock_units')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_stock_unit_code', columns: ['unit_code'])]
#[ORM\UniqueConstraint(name: 'uniq_inventory_stock_unit_qr_token', columns: ['qr_token'])]
#[ORM\Index(name: 'idx_inventory_stock_unit_variant_status', columns: ['material_variant_id', 'status'])]
#[ORM\Index(name: 'idx_inventory_stock_unit_lot_status', columns: ['lot_id', 'status'])]
#[ORM\HasLifecycleCallbacks]
class InventoryStockUnit
{
    use Timestampable;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'material_variant_id', nullable: false, onDelete: 'RESTRICT')]
    private MaterialVariant $variant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'lot_id', nullable: true, onDelete: 'RESTRICT')]
    private ?InventoryLot $lot = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_stock_unit_id', nullable: true, onDelete: 'RESTRICT')]
    private ?self $parentStockUnit = null;

    #[ORM\Column(name: 'unit_code', length: 40)]
    private string $unitCode;

    #[ORM\Column(name: 'qr_token', length: 64)]
    private string $qrToken;

    #[ORM\Column(name: 'unit_kind', length: 20, enumType: InventoryStockUnitKind::class)]
    private InventoryStockUnitKind $kind;

    #[ORM\Column(name: 'status', length: 20, enumType: InventoryStockUnitStatus::class)]
    private InventoryStockUnitStatus $status = InventoryStockUnitStatus::AVAILABLE;

    #[ORM\Column(name: 'initial_quantity', type: Types::DECIMAL, precision: 20, scale: 6)]
    private string $initialQuantity;

    #[ORM\Column(name: 'remaining_quantity', type: Types::DECIMAL, precision: 20, scale: 6)]
    private string $remainingQuantity;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'physical_attributes', type: Types::JSON, nullable: true)]
    private ?array $physicalAttributes = null;

    #[ORM\Column(name: 'storage_location', length: 100, nullable: true)]
    private ?string $storageLocation = null;

    #[ORM\Column(name: 'received_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    public function __construct(
        MaterialVariant $variant,
        string $initialQuantity,
        InventoryStockUnitKind $kind = InventoryStockUnitKind::RECEIVED,
        ?self $parentStockUnit = null,
    ) {
        if ($parentStockUnit !== null && $parentStockUnit->variant !== $variant) {
            throw new \DomainException('Un sobrante debe conservar la misma variante de su unidad de origen.');
        }
        if ($kind === InventoryStockUnitKind::SURPLUS && $parentStockUnit === null) {
            throw new \InvalidArgumentException('Un sobrante recuperable debe indicar su unidad física de origen.');
        }
        if ($kind === InventoryStockUnitKind::RECEIVED && $parentStockUnit !== null) {
            throw new \InvalidArgumentException('Una unidad recibida no puede tener unidad física de origen.');
        }

        $this->variant = $variant;
        $this->kind = $kind;
        $this->parentStockUnit = $parentStockUnit;
        $this->initialQuantity = self::positiveDecimal($initialQuantity, 'La cantidad inicial');
        $this->remainingQuantity = $this->initialQuantity;
        $this->unitCode = 'IMU-'.strtoupper(bin2hex(random_bytes(6)));
        $this->qrToken = bin2hex(random_bytes(24));
        $this->receivedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVariant(): MaterialVariant
    {
        return $this->variant;
    }

    public function getLot(): ?InventoryLot
    {
        return $this->lot;
    }

    public function assignLot(?InventoryLot $lot): self
    {
        if ($lot !== null && $lot->getVariant() !== $this->variant) {
            throw new \DomainException('El lote debe pertenecer a la misma variante de la unidad física.');
        }
        $this->lot = $lot;

        return $this;
    }

    public function getParentStockUnit(): ?self
    {
        return $this->parentStockUnit;
    }

    public function getUnitCode(): string
    {
        return $this->unitCode;
    }

    public function getQrToken(): string
    {
        return $this->qrToken;
    }

    public function getQrPayload(): string
    {
        return 'PF:MAT:1:'.$this->qrToken;
    }

    public function getKind(): InventoryStockUnitKind
    {
        return $this->kind;
    }

    public function getStatus(): InventoryStockUnitStatus
    {
        return $this->status;
    }

    public function getInitialQuantity(): string
    {
        return $this->initialQuantity;
    }

    public function getRemainingQuantity(): string
    {
        return $this->remainingQuantity;
    }

    /** @return array<string, mixed>|null */
    public function getPhysicalAttributes(): ?array
    {
        return $this->physicalAttributes;
    }

    /** @param array<string, mixed>|null $physicalAttributes */
    public function setPhysicalAttributes(?array $physicalAttributes): self
    {
        $this->physicalAttributes = $physicalAttributes === [] ? null : $physicalAttributes;

        return $this;
    }

    public function getStorageLocation(): ?string
    {
        return $this->storageLocation;
    }

    public function setStorageLocation(?string $storageLocation): self
    {
        $storageLocation = trim((string) $storageLocation);
        if (mb_strlen($storageLocation) > 100) {
            throw new \InvalidArgumentException('La ubicación de bodega no puede superar 100 caracteres.');
        }
        $this->storageLocation = $storageLocation !== '' ? $storageLocation : null;

        return $this;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function isAvailable(): bool
    {
        return $this->status === InventoryStockUnitStatus::AVAILABLE;
    }

    public function block(): void
    {
        if ($this->status !== InventoryStockUnitStatus::AVAILABLE) {
            throw new \DomainException('Solo se puede bloquear una unidad física disponible.');
        }
        $this->status = InventoryStockUnitStatus::BLOCKED;
    }

    public function release(): void
    {
        if ($this->status !== InventoryStockUnitStatus::BLOCKED) {
            throw new \DomainException('Solo se puede liberar una unidad física bloqueada.');
        }
        $this->status = InventoryStockUnitStatus::AVAILABLE;
    }

    public function consume(string $quantity): void
    {
        if (!$this->isAvailable()) {
            throw new \DomainException('La unidad física no está disponible para consumo.');
        }

        $quantity = self::positiveDecimal($quantity, 'La cantidad consumida');
        $remaining = BigDecimal::of($this->remainingQuantity);
        $consumed = BigDecimal::of($quantity);
        if ($consumed->isGreaterThan($remaining)) {
            throw new \DomainException('La cantidad consumida supera el saldo de la unidad física.');
        }

        $this->remainingQuantity = $remaining->minus($consumed)->toScale(6)->__toString();
        if (BigDecimal::of($this->remainingQuantity)->isZero()) {
            $this->status = InventoryStockUnitStatus::EXHAUSTED;
        }
    }

    /** @param array<string, mixed>|null $physicalAttributes */
    public function separateSurplus(string $quantity, ?string $storageLocation = null, ?array $physicalAttributes = null): self
    {
        if (!$this->isAvailable()) {
            throw new \DomainException('Solo se puede separar un sobrante de una unidad física disponible.');
        }

        $quantity = self::positiveDecimal($quantity, 'La cantidad del sobrante');
        $remaining = BigDecimal::of($this->remainingQuantity);
        $surplusQuantity = BigDecimal::of($quantity);
        if ($surplusQuantity->isGreaterThan($remaining)) {
            throw new \DomainException('El sobrante separado supera el saldo físico restante de la unidad.');
        }

        $this->remainingQuantity = $remaining->minus($surplusQuantity)->toScale(6)->__toString();
        if (BigDecimal::of($this->remainingQuantity)->isZero()) {
            $this->status = InventoryStockUnitStatus::EXHAUSTED;
        }

        $surplus = new self($this->variant, $quantity, InventoryStockUnitKind::SURPLUS, $this);
        $surplus->assignLot($this->lot);
        $surplus->setStorageLocation($storageLocation ?? $this->storageLocation);
        $surplus->setPhysicalAttributes($physicalAttributes ?? $this->physicalAttributes);

        return $surplus;
    }

    public function dispose(): void
    {
        if ($this->status === InventoryStockUnitStatus::EXHAUSTED) {
            throw new \DomainException('Una unidad agotada no puede desecharse de nuevo.');
        }
        if ($this->status === InventoryStockUnitStatus::DISPOSED) {
            throw new \DomainException('La unidad física ya fue desechada.');
        }
        $this->status = InventoryStockUnitStatus::DISPOSED;
    }

    private static function positiveDecimal(string $value, string $field): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field.' no tiene un formato válido.');
        }

        $decimal = BigDecimal::of($value)->toScale(6);
        if ($decimal->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new \InvalidArgumentException($field.' debe ser mayor que cero.');
        }

        return $decimal->__toString();
    }
}
