<?php
declare(strict_types=1);
namespace App\Entity\Suppliers;
use App\Entity\Catalog\MeasurementUnit; use App\Entity\Common\Timestampable; use App\Entity\Materials\MaterialVariant; use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity,ORM\Table(name:'supplier_material_variants'),ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name:'uniq_supplier_material_variant',columns:['supplier_id','material_variant_id'])]
/** Oferta comercial de un proveedor para una presentación específica de material. */
class SupplierMaterialVariant
{
 use Timestampable; /** Identificador interno de la oferta. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
 /** Proveedor que comercializa la presentación. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'supplier_id',nullable:false,onDelete:'RESTRICT')] private Supplier $supplier;
 /** Presentación exacta ofrecida. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'material_variant_id',nullable:false,onDelete:'RESTRICT')] private MaterialVariant $variant;
 /** Unidad comercial a la que corresponde el costo. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'purchase_unit_id',nullable:false,onDelete:'RESTRICT')] private MeasurementUnit $purchaseUnit;
 /** Código con el que el proveedor identifica el artículo. */ #[ORM\Column(name:'supplier_sku',length:100,nullable:true)] private ?string $supplierSku=null;
 /** Costo unitario vigente expresado exclusivamente en MXN. */ #[ORM\Column(name:'unit_cost_mxn',type:Types::DECIMAL,precision:19,scale:6)] private string $unitCostMxn;
 /** Inicio UTC de la vigencia comercial. */ #[ORM\Column(name:'valid_from',type:Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $validFrom;
 /** Fin UTC opcional de la vigencia comercial. */ #[ORM\Column(name:'valid_until',type:Types::DATETIME_IMMUTABLE,nullable:true)] private ?\DateTimeImmutable $validUntil=null;
 /** Indica si PRINTFLOW debe proponer esta oferta primero. */ #[ORM\Column(name:'is_preferred')] private bool $isPreferred=false; /** Orden relativo entre alternativas del mismo artículo. */ #[ORM\Column(name:'priority')] private int $priority=100; /** Indica si puede seleccionarse en nuevas compras. */ #[ORM\Column(name:'is_active')] private bool $isActive=true;
 public function __construct(Supplier $supplier,MaterialVariant $variant,MeasurementUnit $unit,string $cost){$this->supplier=$supplier;$this->variant=$variant;$this->purchaseUnit=$unit;$this->unitCostMxn=$cost;$this->validFrom=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));$this->initializeTimestamps();}
}
