<?php
declare(strict_types=1);
namespace App\Entity\Materials;
use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Common\Timestampable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity,ORM\Table(name:'material_variants'),ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name:'uniq_material_variants_code',columns:['code'])]
/** Presentación concreta que se compra, almacena, consume y costea. */
class MaterialVariant
{
    use Timestampable;
    /** Identificador interno de la presentación. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
    /** Concepto general al que pertenece la presentación. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'material_id',nullable:false,onDelete:'RESTRICT')] private Material $material;
    /** Marca comercial opcional de esta presentación. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'brand_id',onDelete:'RESTRICT')] private ?Brand $brand=null;
    /** Color físico opcional del insumo. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'color_id',onDelete:'RESTRICT')] private ?Color $color=null;
    /** Acabado superficial opcional. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'finish_id',onDelete:'RESTRICT')] private ?Finish $finish=null;
    /** Tecnología de adhesivo opcional. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'adhesive_type_id',onDelete:'RESTRICT')] private ?AdhesiveType $adhesiveType=null;
    /** Unidad en la que el proveedor vende la presentación. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'purchase_unit_id',nullable:false,onDelete:'RESTRICT')] private MeasurementUnit $purchaseUnit;
    /** Unidad utilizada para expresar su saldo físico. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'inventory_unit_id',nullable:false,onDelete:'RESTRICT')] private MeasurementUnit $inventoryUnit;
    /** Unidad utilizada por las recetas y consumos productivos. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'consumption_unit_id',nullable:false,onDelete:'RESTRICT')] private MeasurementUnit $consumptionUnit;
    /** Código interno único de la presentación. */ #[ORM\Column(length:80)] private string $code;
    /** SKU opcional asignado por el fabricante. */ #[ORM\Column(name:'manufacturer_sku',length:100,nullable:true)] private ?string $manufacturerSku=null;
    /** Código de barras opcional de la presentación. */ #[ORM\Column(length:80,nullable:true)] private ?string $barcode=null;
    /** Magnitudes y datos técnicos estructurados: ancho, largo, espesor, gramaje, volumen o peso con sus unidades. */ #[ORM\Column(type:Types::JSON)] private array $specifications=[];
    /** Cantidad de unidades de inventario contenidas en una unidad de compra. */ #[ORM\Column(name:'purchase_to_inventory_factor',type:Types::DECIMAL,precision:24,scale:12)] private string $purchaseToInventoryFactor='1.000000000000';
    /** Cantidad de unidades de consumo contenidas en una unidad de inventario. */ #[ORM\Column(name:'inventory_to_consumption_factor',type:Types::DECIMAL,precision:24,scale:12)] private string $inventoryToConsumptionFactor='1.000000000000';
    /** Costo orientativo en MXN; no reemplaza costos de compra ni promedio móvil. */ #[ORM\Column(name:'reference_cost_mxn',type:Types::DECIMAL,precision:19,scale:6,nullable:true)] private ?string $referenceCostMxn=null;
    /** Nivel mínimo deseado expresado en la unidad de inventario. */ #[ORM\Column(name:'minimum_stock',type:Types::DECIMAL,precision:20,scale:6)] private string $minimumStock='0.000000';
    /** Saldo que dispara una sugerencia de reabasto. */ #[ORM\Column(name:'reorder_point',type:Types::DECIMAL,precision:20,scale:6)] private string $reorderPoint='0.000000';
    /** Cantidad sugerida para reabastecer. */ #[ORM\Column(name:'reorder_quantity',type:Types::DECIMAL,precision:20,scale:6)] private string $reorderQuantity='0.000000';
    /** Obliga a identificar lote en movimientos físicos. */ #[ORM\Column(name:'lot_controlled')] private bool $lotControlled=false;
    /** Obliga a registrar caducidad en los lotes. */ #[ORM\Column(name:'expiration_controlled')] private bool $expirationControlled=false;
    /** Señala la opción propuesta cuando una receta solo exige el material general. */ #[ORM\Column(name:'is_default')] private bool $isDefault=false;
    /** Permite utilizar esta presentación en nuevas operaciones. */ #[ORM\Column(name:'is_active')] private bool $isActive=true;
    public function __construct(Material $material,string $code,MeasurementUnit $purchaseUnit,MeasurementUnit $inventoryUnit,MeasurementUnit $consumptionUnit){$this->material=$material;$this->code=strtoupper(trim($code));$this->purchaseUnit=$purchaseUnit;$this->inventoryUnit=$inventoryUnit;$this->consumptionUnit=$consumptionUnit;$this->initializeTimestamps();}
    public function getId():?int{return $this->id;} public function getMaterial():Material{return $this->material;} public function getCode():string{return $this->code;} public function isLotControlled():bool{return $this->lotControlled;} public function isExpirationControlled():bool{return $this->expirationControlled;}
}
