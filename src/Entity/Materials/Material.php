<?php

namespace App\Entity\Materials;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Suppliers\Supplier;
use App\Repository\Materials\MaterialRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MaterialRepository::class)]
#[ORM\Table(name: 'materials')]
#[ORM\UniqueConstraint(name: 'uniq_materials_code', columns: ['code'])]
#[ORM\Index(name: 'idx_materials_active_name', columns: ['is_active', 'name'])]
#[ORM\Index(name: 'idx_materials_category_active', columns: ['category_id', 'is_active'])]
#[ORM\Index(name: 'idx_materials_unit_active', columns: ['measurement_unit_id', 'is_active'])]
#[ORM\Index(name: 'idx_materials_supplier_active', columns: ['primary_supplier_id', 'is_active'])]
#[ORM\HasLifecycleCallbacks]
/**
 * Concepto técnico general del insumo; las presentaciones físicas viven en MaterialVariant.
 *
 * Campos legados mantenidos durante la transición: measurementUnit,
 * primarySupplier, referenceCost y minimumStock. Los equivalentes normalizados
 * corresponden a unidades predeterminadas, variantes y ofertas de proveedor.
 * category clasifica; code/name/description identifican; materialType y los
 * indicadores describen comportamiento; storageConditions, technicalNotes y
 * notes documentan; isActive controla la baja lógica; createdAt y
 * updatedAt registran auditoría UTC.
 */
class Material
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Unidad predeterminada para expresar el saldo de futuras variantes. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'default_inventory_unit_id', nullable: true, onDelete: 'RESTRICT')]
    private ?MeasurementUnit $defaultInventoryUnit = null;

    /** Unidad predeterminada para las recetas que consumen este material. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'default_consumption_unit_id', nullable: true, onDelete: 'RESTRICT')]
    private ?MeasurementUnit $defaultConsumptionUnit = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'category_id', nullable: false, onDelete: 'RESTRICT')]
    private MaterialCategory $category;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'measurement_unit_id', nullable: false, onDelete: 'RESTRICT')]
    private MeasurementUnit $measurementUnit;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'primary_supplier_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Supplier $primarySupplier = null;

    #[ORM\Column(length: 80)]
    private string $code;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Clasificación técnica general del insumo, independiente de su presentación. */
    #[ORM\Column(name: 'material_type', length: 30)]
    private string $materialType = 'CONSUMABLE';

    /** Indica si sus variantes mantienen saldo físico. */
    #[ORM\Column(name: 'is_stock_item')]
    private bool $isStockItem = true;

    /** Indica si puede abastecerse mediante compras. */
    #[ORM\Column(name: 'is_purchasable')]
    private bool $isPurchasable = true;

    /** Indica si puede formar parte de una BOM y consumirse en producción. */
    #[ORM\Column(name: 'is_consumable')]
    private bool $isConsumable = true;

    /** Señala que requiere manejo o almacenamiento de riesgo. */
    #[ORM\Column(name: 'is_hazardous')]
    private bool $isHazardous = false;

    /** Valor predeterminado de trazabilidad por lote para sus variantes. */
    #[ORM\Column(name: 'requires_lot_control')]
    private bool $requiresLotControl = false;

    /** Valor predeterminado que obliga a controlar caducidad. */
    #[ORM\Column(name: 'requires_expiration_control')]
    private bool $requiresExpirationControl = false;

    /** Desperdicio porcentual sugerido cuando una receta no define uno más específico. */
    #[ORM\Column(name: 'default_waste_percentage', type: Types::DECIMAL, precision: 7, scale: 4)]
    private string $defaultWastePercentage = '0.0000';

    /** Condiciones generales de almacenamiento y seguridad. */
    #[ORM\Column(name: 'storage_conditions', type: Types::TEXT, nullable: true)]
    private ?string $storageConditions = null;

    /** Observaciones técnicas que no sustituyen atributos estructurados de variante. */
    #[ORM\Column(name: 'technical_notes', type: Types::TEXT, nullable: true)]
    private ?string $technicalNotes = null;

    #[ORM\Column(name: 'reference_cost', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $referenceCost = '0.00';

    #[ORM\Column(name: 'minimum_stock', type: Types::DECIMAL, precision: 12, scale: 3)]
    private string $minimumStock = '0.000';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(
        name: 'created_at',
        type: 'datetime_immutable',
        options: ['comment' => '(DC2Type:datetime_immutable)'],
    )]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(
        name: 'updated_at',
        type: 'datetime_immutable',
        options: ['comment' => '(DC2Type:datetime_immutable)'],
    )]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): MaterialCategory
    {
        return $this->category;
    }

    public function setCategory(MaterialCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getMeasurementUnit(): MeasurementUnit
    {
        return $this->measurementUnit;
    }

    public function setMeasurementUnit(MeasurementUnit $measurementUnit): self
    {
        $this->measurementUnit = $measurementUnit;

        return $this;
    }

    public function getPrimarySupplier(): ?Supplier
    {
        return $this->primarySupplier;
    }

    public function setPrimarySupplier(?Supplier $primarySupplier): self
    {
        $this->primarySupplier = $primarySupplier;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = strtoupper(trim($code));

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $description = trim((string) $description);
        $this->description = $description !== '' ? $description : null;

        return $this;
    }

    public function getReferenceCost(): string
    {
        return $this->referenceCost;
    }

    public function setReferenceCost(string $referenceCost): self
    {
        $this->referenceCost = $this->normalizeDecimal(
            $referenceCost,
            2,
            'El costo de referencia no tiene un formato válido.',
        );

        return $this;
    }

    public function getMinimumStock(): string
    {
        return $this->minimumStock;
    }

    public function setMinimumStock(string $minimumStock): self
    {
        $this->minimumStock = $this->normalizeDecimal(
            $minimumStock,
            3,
            'El stock mínimo no tiene un formato válido.',
        );

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $notes = trim((string) $notes);
        $this->notes = $notes !== '' ? $notes : null;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable(
            'now',
            new \DateTimeZone('UTC'),
        );
    }

    private function normalizeDecimal(
        string $value,
        int $scale,
        string $errorMessage,
    ): string {
        $value = trim(str_replace(',', '.', $value));

        $integerDigits = 12 - $scale;

        $pattern = sprintf(
            '/^(?:0|[1-9]\d{0,%d})(?:\.\d{1,%d})?$/D',
            $integerDigits - 1,
            $scale,
        );

        if (preg_match($pattern, $value) !== 1) {
            throw new \InvalidArgumentException($errorMessage);
        }

        [$integer, $decimal] = array_pad(
            explode('.', $value, 2),
            2,
            '',
        );

        $integer = ltrim($integer, '0') ?: '0';

        return $integer.'.'.str_pad($decimal, $scale, '0');
    }
}
