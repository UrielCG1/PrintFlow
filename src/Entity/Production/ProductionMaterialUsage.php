<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Common\Timestampable;
use App\Entity\Inventory\InventoryLot;
use App\Entity\Inventory\InventoryMovement;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Users\User;
use App\Repository\Production\ProductionMaterialUsageRepository;
use App\Enum\Inventory\QuantitySource;
use App\Enum\Production\CalculationMethod;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** El consumo real es inmutable; la previsión se puede corregir antes de iniciar la operación. */
#[ORM\Entity(repositoryClass: ProductionMaterialUsageRepository::class)]
#[ORM\Table(name: 'production_material_usages')]
#[ORM\Index(name: 'idx_usage_plan_execution', columns: ['operation_plan_id', 'operation_execution_id'])]
#[ORM\HasLifecycleCallbacks]
class ProductionMaterialUsage
{
    use Timestampable;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'service_order_item_id', nullable: false, onDelete: 'RESTRICT')]
    private ServiceOrderItem $serviceOrderItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'operation_plan_id', nullable: true, onDelete: 'RESTRICT')]
    private ?ServiceOrderOperationPlan $operationPlan = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'operation_execution_id', nullable: true, onDelete: 'RESTRICT')]
    private ?ProductionOperationExecution $operationExecution = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'material_variant_id', nullable: true, onDelete: 'RESTRICT')]
    private ?MaterialVariant $variant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'lot_id', onDelete: 'RESTRICT')]
    private ?InventoryLot $lot = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'unit_id', nullable: true, onDelete: 'RESTRICT')]
    private ?MeasurementUnit $unit;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'inventory_movement_id', onDelete: 'RESTRICT')]
    private ?InventoryMovement $movement = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'measured_by', onDelete: 'RESTRICT')]
    private ?User $measuredBy = null;

    /** La previsión puede no existir cuando no hay BOM válida. */
    #[ORM\Column(name: 'planned_quantity', type: Types::DECIMAL, precision: 20, scale: 6, nullable: true)]
    private ?string $plannedQuantity = null;

    /** Longitud o cantidad física prevista en la unidad de inventario; se usa en rollos. */
    #[ORM\Column(name: 'planned_inventory_quantity', type: Types::DECIMAL, precision: 20, scale: 6, nullable: true)]
    private ?string $plannedInventoryQuantity = null;

    #[ORM\Column(name: 'actual_quantity', type: Types::DECIMAL, precision: 20, scale: 6, nullable: true)]
    private ?string $actualQuantity = null;

    /** Cantidad física realmente descontada en la unidad de inventario; se usa en rollos. */
    #[ORM\Column(name: 'actual_inventory_quantity', type: Types::DECIMAL, precision: 20, scale: 6, nullable: true)]
    private ?string $actualInventoryQuantity = null;

    /** Compatibilidad histórica: no representa movimiento de inventario. */
    #[ORM\Column(name: 'posted_quantity', type: Types::DECIMAL, precision: 20, scale: 6, nullable: true)]
    private ?string $postedQuantity = null;

    #[ORM\Column(name: 'waste_quantity', type: Types::DECIMAL, precision: 20, scale: 6, nullable: true)]
    private ?string $wasteQuantity = null;

    #[ORM\Column(name: 'quantity_source', length: 20, enumType: QuantitySource::class)]
    private QuantitySource $quantitySource = QuantitySource::ESTIMATED;

    #[ORM\Column(name: 'measurement_method', length: 30, nullable: true)]
    private ?string $measurementMethod = null;

    #[ORM\Column(name: 'waste_reason', length: 255, nullable: true)]
    private ?string $wasteReason = null;

    #[ORM\Column(name: 'calculation_method', length: 30, enumType: CalculationMethod::class)]
    private CalculationMethod $calculationMethod;

    #[ORM\Column(name: 'calculation_method_version')]
    private int $calculationMethodVersion;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'calculation_snapshot', type: Types::JSON)]
    private array $calculationSnapshot;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'material_snapshot', type: Types::JSON, nullable: true)]
    private ?array $materialSnapshot = null;

    #[ORM\Column(name: 'material_name_snapshot', length: 180)]
    private string $materialNameSnapshot;

    #[ORM\Column(name: 'unit_label_snapshot', length: 40)]
    private string $unitLabelSnapshot;

    #[ORM\Column(name: 'measured_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $measuredAt = null;

    /** @param array<string, mixed> $snapshot */
    public function __construct(
        ServiceOrderItem $item,
        ?MaterialVariant $variant,
        ?MeasurementUnit $unit,
        ?string $planned,
        CalculationMethod $method,
        int $version,
        array $snapshot,
        ?string $materialNameSnapshot = null,
        ?string $unitLabelSnapshot = null,
    ) {
        if ($version < 1) {
            throw new \InvalidArgumentException('La versión del cálculo debe ser positiva.');
        }
        $materialNameSnapshot = trim((string) ($materialNameSnapshot ?? $variant?->getMaterial()->getName()));
        $unitLabelSnapshot = trim((string) ($unitLabelSnapshot ?? $unit?->getSymbol()));
        if ($materialNameSnapshot === '' || mb_strlen($materialNameSnapshot) > 180) {
            throw new \InvalidArgumentException('El nombre del material debe tener entre 1 y 180 caracteres.');
        }
        if ($unitLabelSnapshot === '' || mb_strlen($unitLabelSnapshot) > 40) {
            throw new \InvalidArgumentException('La unidad debe tener entre 1 y 40 caracteres.');
        }
        $this->serviceOrderItem = $item;
        $this->variant = $variant;
        $this->unit = $unit;
        $this->materialNameSnapshot = $materialNameSnapshot;
        $this->unitLabelSnapshot = $unitLabelSnapshot;
        $this->plannedQuantity = self::decimalOrNull($planned, 'La cantidad prevista');
        $this->postedQuantity = $this->plannedQuantity;
        $this->calculationMethod = $method;
        $this->calculationMethodVersion = $version;
        $this->calculationSnapshot = $snapshot;
        $this->initializeTimestamps();
    }

    public function assignPlanContext(ServiceOrderOperationPlan $plan): self
    {
        if ($plan->getServiceOrderItem() !== $this->serviceOrderItem) {
            throw new \DomainException('El material previsto debe pertenecer a la partida indicada.');
        }
        if ($this->operationExecution !== null) {
            throw new \DomainException('Un consumo real no puede volver a convertirse en material previsto.');
        }
        $this->operationPlan = $plan;

        return $this;
    }

    /** Actualiza la previsión de una operación aún no iniciada; el cambio queda auditado por el caso de uso. */
    public function revisePlanned(MaterialVariant $variant, string $quantity, ?string $inventoryQuantity = null): self
    {
        if ($this->operationPlan === null || $this->operationExecution !== null || $this->actualQuantity !== null) {
            throw new \DomainException('Solo se puede corregir material previsto antes de iniciar la operación.');
        }
        $normalized = self::positiveDecimal($quantity, 'La cantidad prevista');
        $this->variant = $variant;
        $this->unit = $variant->getConsumptionUnit();
        $this->plannedQuantity = $this->postedQuantity = $normalized;
        $this->plannedInventoryQuantity = self::decimalOrNull($inventoryQuantity, 'La cantidad física prevista');
        $this->materialNameSnapshot = $variant->getMaterial()->getName();
        $this->unitLabelSnapshot = $variant->getConsumptionUnit()->getSymbol();
        $this->calculationSnapshot = ['source' => 'printing-production-plan', 'version' => 1, 'variant_code' => $variant->getCode()];

        return $this;
    }

    /** Registra la cantidad física prevista sin reinterpretar la cantidad de producción. */
    public function setPlannedInventoryQuantity(?string $quantity): self
    {
        if ($this->operationPlan === null || $this->operationExecution !== null || $this->actualQuantity !== null) {
            throw new \DomainException('Solo se puede indicar la cantidad física prevista antes de iniciar la operación.');
        }
        $this->plannedInventoryQuantity = self::decimalOrNull($quantity, 'La cantidad física prevista');

        return $this;
    }

    /** @param array<string, mixed> $materialSnapshot */
    public function assignExecutionContext(
        ServiceOrderOperationPlan $plan,
        ProductionOperationExecution $execution,
        array $materialSnapshot,
    ): self {
        if ($plan->getServiceOrderItem() !== $this->serviceOrderItem || $execution->getPlan() !== $plan) {
            throw new \DomainException('El consumo debe pertenecer a la partida, plan e intento indicados.');
        }
        $this->operationPlan = $plan;
        $this->operationExecution = $execution;
        $this->materialSnapshot = $materialSnapshot;

        return $this;
    }

    public function recordActual(
        string $actual,
        ?string $waste,
        QuantitySource $source,
        User $user,
        string $method,
        ?string $wasteReason = null,
    ): void {
        if ($source === QuantitySource::ESTIMATED) {
            throw new \InvalidArgumentException('Un consumo real no puede marcarse como estimado.');
        }
        $total = self::positiveDecimal($actual, 'El material total consumido');
        if ($waste === null || trim($waste) === '') {
            throw new \InvalidArgumentException('Registra el desperdicio explícitamente; captura 0 cuando no exista.');
        }
        $wasteValue = self::nonNegativeDecimal($waste, 'El desperdicio');
        if (BigDecimal::of($wasteValue)->isGreaterThan(BigDecimal::of($total))) {
            throw new \InvalidArgumentException('El desperdicio no puede ser mayor que el material total consumido.');
        }
        $method = trim($method);
        if ($method === '' || mb_strlen($method) > 30) {
            throw new \InvalidArgumentException('Indica el método de medición del consumo.');
        }
        $wasteReason = trim((string) $wasteReason);
        if (BigDecimal::of($wasteValue)->isGreaterThan(BigDecimal::zero()) && $wasteReason === '') {
            throw new \InvalidArgumentException('Explica el desperdicio cuando su cantidad es mayor que cero.');
        }
        if (mb_strlen($wasteReason) > 255) {
            throw new \InvalidArgumentException('El motivo del desperdicio no puede superar 255 caracteres.');
        }
        $this->actualQuantity = $this->postedQuantity = $total;
        $this->wasteQuantity = $wasteValue;
        $this->quantitySource = $source;
        $this->measuredBy = $user;
        $this->measurementMethod = $method;
        $this->wasteReason = $wasteReason !== '' ? $wasteReason : null;
        $this->measuredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** La cantidad física se conserva aparte del área o consumo de Producción. */
    public function recordActualInventoryQuantity(?string $quantity): self
    {
        if ($this->actualQuantity === null) {
            throw new \DomainException('Registra primero el consumo de Producción antes de indicar la cantidad física.');
        }
        $normalized = self::decimalOrNull($quantity, 'La cantidad física real');
        if ($normalized !== null && BigDecimal::of($normalized)->isZero()) {
            throw new \InvalidArgumentException('La cantidad física real debe ser mayor que cero.');
        }
        $this->actualInventoryQuantity = $normalized;

        return $this;
    }

    public function getId(): ?int { return $this->id; }
    public function getServiceOrderItem(): ServiceOrderItem { return $this->serviceOrderItem; }
    public function getOperationPlan(): ?ServiceOrderOperationPlan { return $this->operationPlan; }
    public function getOperationExecution(): ?ProductionOperationExecution { return $this->operationExecution; }
    public function getVariant(): ?MaterialVariant { return $this->variant; }
    public function getUnit(): ?MeasurementUnit { return $this->unit; }
    public function getMaterialNameSnapshot(): string { return $this->materialNameSnapshot; }
    public function getUnitLabelSnapshot(): string { return $this->unitLabelSnapshot; }
    public function getPlannedQuantity(): ?string { return $this->plannedQuantity; }
    public function getPlannedInventoryQuantity(): ?string { return $this->plannedInventoryQuantity; }
    /** @return array<string, mixed> */ public function getCalculationSnapshot(): array { return $this->calculationSnapshot; }
    public function getActualQuantity(): ?string { return $this->actualQuantity; }
    public function getActualInventoryQuantity(): ?string { return $this->actualInventoryQuantity; }
    public function getPostedQuantity(): ?string { return $this->postedQuantity; }
    public function getWasteQuantity(): ?string { return $this->wasteQuantity; }
    public function getQuantitySource(): QuantitySource { return $this->quantitySource; }
    public function getMeasuredAt(): ?\DateTimeImmutable { return $this->measuredAt; }
    /** @return array<string, mixed>|null */ public function getMaterialSnapshot(): ?array { return $this->materialSnapshot; }
    public function getMovement(): ?InventoryMovement { return $this->movement; }

    public function attachInventoryMovement(InventoryMovement $movement): self
    {
        $movementVariant = $movement->getVariant();
        if ($this->variant === null || ($movementVariant !== $this->variant && ($movementVariant->getId() === null || $movementVariant->getId() !== $this->variant->getId()))) {
            throw new \DomainException('El movimiento de inventario debe corresponder al material consumido.');
        }
        if ($this->movement !== null) {
            throw new \DomainException('El consumo ya tiene un movimiento físico de inventario.');
        }

        $this->movement = $movement;

        return $this;
    }

    private static function decimalOrNull(?string $value, string $field): ?string
    {
        return $value === null || trim($value) === '' ? null : self::nonNegativeDecimal($value, $field);
    }

    private static function positiveDecimal(string $value, string $field): string
    {
        $decimal = self::nonNegativeDecimal($value, $field);
        if (BigDecimal::of($decimal)->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new \InvalidArgumentException($field.' debe ser mayor que cero.');
        }
        return $decimal;
    }

    private static function nonNegativeDecimal(string $value, string $field): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field.' no tiene un formato válido.');
        }
        return BigDecimal::of($value)->toScale(6)->__toString();
    }
}
