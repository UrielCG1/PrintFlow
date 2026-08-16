<?php
declare(strict_types=1);
namespace App\Entity\Inventory;
use App\Entity\Common\Timestampable; use App\Entity\Materials\MaterialVariant; use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity,ORM\Table(name:'inventory_lots'),ORM\HasLifecycleCallbacks]
/** Identifica una recepción trazable de una variante y sus fechas/costo originales. */
class InventoryLot
{
 use Timestampable; /** Identificador interno del lote. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null; /** Presentación física contenida en el lote. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'material_variant_id',nullable:false,onDelete:'RESTRICT')] private MaterialVariant $variant;
 /** Folio único generado por PRINTFLOW para la recepción. */ #[ORM\Column(name:'internal_lot_number',length:100,unique:true)] private string $internalLotNumber; /** Número opcional impreso por fabricante o proveedor. */ #[ORM\Column(name:'manufacturer_lot_number',length:100,nullable:true)] private ?string $manufacturerLotNumber=null;
 /** Fecha de fabricación declarada, cuando está disponible. */ #[ORM\Column(name:'manufactured_at',type:Types::DATE_IMMUTABLE,nullable:true)] private ?\DateTimeImmutable $manufacturedAt=null; /** Fecha de caducidad, obligatoria por regla de dominio si la variante la controla. */ #[ORM\Column(name:'expires_at',type:Types::DATE_IMMUTABLE,nullable:true)] private ?\DateTimeImmutable $expiresAt=null;
 /** Costo MXN de la unidad recibida, conservado como evidencia histórica. */ #[ORM\Column(name:'received_unit_cost_mxn',type:Types::DECIMAL,precision:19,scale:6,nullable:true)] private ?string $receivedUnitCostMxn=null;
 public function __construct(MaterialVariant $variant,string $internalNumber){$this->variant=$variant;$this->internalLotNumber=strtoupper(trim($internalNumber));$this->initializeTimestamps();}
}
