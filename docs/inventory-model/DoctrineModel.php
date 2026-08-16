<?php

declare(strict_types=1);

/*
 * Mapeo ORM de referencia. Un proyecto real debe separar una clase por archivo,
 * agregar repositorios y encapsular mutaciones en métodos de dominio.
 * Se conserva fuera de src/ para no activar dos modelos sobre las tablas actuales.
 */

namespace App\Proposal\Inventory;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

trait Timestamps
{
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function initializeTimestamps(): void
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}

#[ORM\Entity, ORM\Table(name: 'measurement_units'), ORM\HasLifecycleCallbacks]
class MeasurementUnit
{
    use Timestamps;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT)] private ?int $id = null;
    #[ORM\ManyToOne(targetEntity: self::class)] #[ORM\JoinColumn(name: 'base_unit_id', onDelete: 'RESTRICT')] private ?self $baseUnit = null;
    #[ORM\Column(length: 20, unique: true)] private string $code;
    #[ORM\Column(length: 80, unique: true)] private string $name;
    #[ORM\Column(length: 20)] private string $symbol;
    #[ORM\Column(name: 'dimension_type', length: 20)] private string $dimensionType;
    #[ORM\Column(name: 'conversion_factor', type: Types::DECIMAL, precision: 24, scale: 12)] private string $conversionFactor = '1.000000000000';
    #[ORM\Column(name: 'decimal_scale', type: Types::SMALLINT)] private int $decimalScale = 6;
    #[ORM\Column(name: 'allows_fraction')] private bool $allowsFraction = true;
    #[ORM\Column(name: 'is_active')] private bool $isActive = true;
    public function __construct() { $this->initializeTimestamps(); }
}

#[ORM\Entity, ORM\Table(name: 'material_categories'), ORM\HasLifecycleCallbacks]
class MaterialCategory
{
    use Timestamps;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT)] private ?int $id = null;
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')] #[ORM\JoinColumn(name: 'parent_id', onDelete: 'RESTRICT')] private ?self $parent = null;
    /** @var Collection<int,self> */ #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)] private Collection $children;
    #[ORM\Column(length: 40, unique: true)] private string $code;
    #[ORM\Column(length: 120)] private string $name;
    #[ORM\Column(type: Types::TEXT, nullable: true)] private ?string $description = null;
    #[ORM\Column(name: 'category_type', length: 24)] private string $categoryType;
    #[ORM\Column(name: 'inventory_controlled')] private bool $inventoryControlled = true;
    #[ORM\Column(name: 'is_active')] private bool $isActive = true;
    public function __construct() { $this->children = new ArrayCollection(); $this->initializeTimestamps(); }
}

#[ORM\Entity, ORM\Table(name: 'materials'), ORM\HasLifecycleCallbacks]
class Material
{
    use Timestamps;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT)] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'category_id', nullable: false, onDelete: 'RESTRICT')] private MaterialCategory $category;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'default_inventory_unit_id', nullable: false, onDelete: 'RESTRICT')] private MeasurementUnit $defaultInventoryUnit;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'default_consumption_unit_id', nullable: false, onDelete: 'RESTRICT')] private MeasurementUnit $defaultConsumptionUnit;
    #[ORM\Column(length: 80, unique: true)] private string $code;
    #[ORM\Column(length: 180)] private string $name;
    #[ORM\Column(type: Types::TEXT, nullable: true)] private ?string $description = null;
    #[ORM\Column(name: 'material_type', length: 30)] private string $materialType;
    #[ORM\Column(name: 'is_stock_item')] private bool $isStockItem = true;
    #[ORM\Column(name: 'is_purchasable')] private bool $isPurchasable = true;
    #[ORM\Column(name: 'is_consumable')] private bool $isConsumable = true;
    #[ORM\Column(name: 'is_hazardous')] private bool $isHazardous = false;
    #[ORM\Column(name: 'requires_lot_control')] private bool $requiresLotControl = false;
    #[ORM\Column(name: 'requires_expiration_control')] private bool $requiresExpirationControl = false;
    #[ORM\Column(name: 'default_waste_percentage', type: Types::DECIMAL, precision: 7, scale: 4)] private string $defaultWastePercentage = '0.0000';
    #[ORM\Column(name: 'storage_conditions', type: Types::TEXT, nullable: true)] private ?string $storageConditions = null;
    #[ORM\Column(name: 'technical_notes', type: Types::TEXT, nullable: true)] private ?string $technicalNotes = null;
    #[ORM\Column(name: 'is_active')] private bool $isActive = true;
    /** @var Collection<int,MaterialVariant> */ #[ORM\OneToMany(mappedBy: 'material', targetEntity: MaterialVariant::class)] private Collection $variants;
    public function __construct() { $this->variants = new ArrayCollection(); $this->initializeTimestamps(); }
}

#[ORM\Entity, ORM\Table(name: 'material_variants'), ORM\HasLifecycleCallbacks]
class MaterialVariant
{
    use Timestamps;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT)] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'variants')] #[ORM\JoinColumn(name: 'material_id', nullable: false, onDelete: 'RESTRICT')] private Material $material;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'purchase_unit_id', nullable: false, onDelete: 'RESTRICT')] private MeasurementUnit $purchaseUnit;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'inventory_unit_id', nullable: false, onDelete: 'RESTRICT')] private MeasurementUnit $inventoryUnit;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'consumption_unit_id', nullable: false, onDelete: 'RESTRICT')] private MeasurementUnit $consumptionUnit;
    #[ORM\Column(length: 80, unique: true)] private string $code;
    #[ORM\Column(name: 'manufacturer_sku', length: 100, nullable: true)] private ?string $manufacturerSku = null;
    #[ORM\Column(length: 80, nullable: true, unique: true)] private ?string $barcode = null;
    #[ORM\Column(type: Types::DECIMAL, precision: 16, scale: 6, nullable: true)] private ?string $width = null;
    #[ORM\Column(type: Types::DECIMAL, precision: 16, scale: 6, nullable: true)] private ?string $length = null;
    #[ORM\Column(type: Types::DECIMAL, precision: 16, scale: 6, nullable: true)] private ?string $thickness = null;
    #[ORM\Column(name: 'purchase_to_inventory_factor', type: Types::DECIMAL, precision: 24, scale: 12)] private string $purchaseToInventoryFactor;
    #[ORM\Column(name: 'inventory_to_consumption_factor', type: Types::DECIMAL, precision: 24, scale: 12)] private string $inventoryToConsumptionFactor;
    #[ORM\Column(name: 'reference_cost_mxn', type: Types::DECIMAL, precision: 19, scale: 6, nullable: true)] private ?string $referenceCostMxn = null;
    #[ORM\Column(name: 'minimum_stock', type: Types::DECIMAL, precision: 20, scale: 6)] private string $minimumStock = '0.000000';
    #[ORM\Column(name: 'is_default')] private bool $isDefault = false;
    #[ORM\Column(name: 'is_active')] private bool $isActive = true;
    public function __construct() { $this->initializeTimestamps(); }
}

#[ORM\Entity, ORM\Table(name: 'supplier_material_variants'), ORM\HasLifecycleCallbacks]
class SupplierMaterialVariant
{
    use Timestamps;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT)] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'material_variant_id', nullable: false, onDelete: 'RESTRICT')] private MaterialVariant $variant;
    #[ORM\Column(name: 'supplier_id', type: Types::BIGINT)] private int $supplierId;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'purchase_unit_id', nullable: false, onDelete: 'RESTRICT')] private MeasurementUnit $purchaseUnit;
    #[ORM\Column(name: 'supplier_sku', length: 100, nullable: true)] private ?string $supplierSku = null;
    #[ORM\Column(name: 'unit_cost_mxn', type: Types::DECIMAL, precision: 19, scale: 6)] private string $unitCostMxn;
    #[ORM\Column(name: 'valid_from', type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $validFrom;
    #[ORM\Column(name: 'valid_until', type: Types::DATETIME_IMMUTABLE, nullable: true)] private ?\DateTimeImmutable $validUntil = null;
    #[ORM\Column(name: 'is_preferred')] private bool $isPreferred = false;
    #[ORM\Column(name: 'is_active')] private bool $isActive = true;
    public function __construct() { $this->initializeTimestamps(); }
}

#[ORM\Entity, ORM\Table(name: 'products'), ORM\HasLifecycleCallbacks]
class Product
{
    use Timestamps;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT)] private ?int $id = null;
    #[ORM\Column(name: 'category_id', type: Types::BIGINT)] private int $categoryId;
    #[ORM\Column(length: 80, unique: true)] private string $code;
    #[ORM\Column(length: 180)] private string $name;
    #[ORM\Column(name: 'product_type', length: 20)] private string $productType;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'sale_unit_id', nullable: false, onDelete: 'RESTRICT')] private MeasurementUnit $saleUnit;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'production_unit_id', nullable: false, onDelete: 'RESTRICT')] private MeasurementUnit $productionUnit;
    #[ORM\Column(name: 'base_price_mxn', type: Types::DECIMAL, precision: 19, scale: 4, nullable: true)] private ?string $basePriceMxn = null;
    #[ORM\Column(name: 'requires_production')] private bool $requiresProduction = false;
    #[ORM\Column(name: 'is_active')] private bool $isActive = true;
    public function __construct() { $this->initializeTimestamps(); }
}

#[ORM\Entity, ORM\Table(name: 'bill_of_material_items'), ORM\HasLifecycleCallbacks]
class BillOfMaterialItem
{
    use Timestamps;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT)] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'RESTRICT')] private Product $product;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'material_id', onDelete: 'RESTRICT')] private ?Material $material = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'material_variant_id', onDelete: 'RESTRICT')] private ?MaterialVariant $variant = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'measurement_unit_id', nullable: false, onDelete: 'RESTRICT')] private MeasurementUnit $unit;
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 6)] private string $quantity;
    #[ORM\Column(name: 'waste_percentage', type: Types::DECIMAL, precision: 7, scale: 4)] private string $wastePercentage = '0.0000';
    #[ORM\Column(name: 'calculation_method', length: 20)] private string $calculationMethod;
    #[ORM\Column(name: 'calculation_method_version', type: Types::SMALLINT)] private int $calculationMethodVersion = 1;
    #[ORM\Column(name: 'calculation_parameters', type: Types::JSON, nullable: true)] private ?array $calculationParameters = null;
    #[ORM\Column] private int $sequence;
    #[ORM\Column(name: 'is_active')] private bool $isActive = true;
    public function __construct() { $this->initializeTimestamps(); }
}

#[ORM\Entity, ORM\Table(name: 'inventory_movements')]
class InventoryMovement
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT)] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'material_variant_id', nullable: false, onDelete: 'RESTRICT')] private MaterialVariant $variant;
    #[ORM\Column(name: 'lot_id', type: Types::BIGINT, nullable: true)] private ?int $lotId = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'unit_id', nullable: false, onDelete: 'RESTRICT')] private MeasurementUnit $unit;
    #[ORM\Column(name: 'movement_type', length: 30)] private string $movementType;
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 6)] private string $quantity;
    #[ORM\Column(name: 'unit_cost_mxn', type: Types::DECIMAL, precision: 19, scale: 6, nullable: true)] private ?string $unitCostMxn = null;
    #[ORM\Column(name: 'source_type', length: 50)] private string $sourceType;
    #[ORM\Column(name: 'source_id', type: Types::BIGINT, nullable: true)] private ?int $sourceId = null;
    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $occurredAt;
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt;
}

#[ORM\Entity, ORM\Table(name: 'production_material_usages'), ORM\HasLifecycleCallbacks]
class ProductionMaterialUsage
{
    use Timestamps;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT)] private ?int $id = null;
    #[ORM\Column(name: 'service_order_item_id')] private int $serviceOrderItemId;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'material_variant_id', nullable: false, onDelete: 'RESTRICT')] private MaterialVariant $variant;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'unit_id', nullable: false, onDelete: 'RESTRICT')] private MeasurementUnit $unit;
    #[ORM\Column(name: 'planned_quantity', type: Types::DECIMAL, precision: 20, scale: 6)] private string $plannedQuantity;
    #[ORM\Column(name: 'actual_quantity', type: Types::DECIMAL, precision: 20, scale: 6, nullable: true)] private ?string $actualQuantity = null;
    #[ORM\Column(name: 'posted_quantity', type: Types::DECIMAL, precision: 20, scale: 6)] private string $postedQuantity;
    #[ORM\Column(name: 'waste_quantity', type: Types::DECIMAL, precision: 20, scale: 6, nullable: true)] private ?string $wasteQuantity = null;
    #[ORM\Column(name: 'quantity_source', length: 20)] private string $quantitySource = 'ESTIMATED';
    #[ORM\Column(name: 'measurement_method', length: 30, nullable: true)] private ?string $measurementMethod = null;
    #[ORM\Column(name: 'waste_reason', length: 255, nullable: true)] private ?string $wasteReason = null;
    #[ORM\Column(name: 'calculation_method', length: 30)] private string $calculationMethod;
    #[ORM\Column(name: 'calculation_method_version', type: Types::SMALLINT)] private int $calculationMethodVersion;
    #[ORM\Column(name: 'calculation_snapshot', type: Types::JSON)] private array $calculationSnapshot;
    #[ORM\Column(name: 'measured_at', type: Types::DATETIME_IMMUTABLE, nullable: true)] private ?\DateTimeImmutable $measuredAt = null;
    public function __construct() { $this->initializeTimestamps(); }
}
