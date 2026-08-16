<?php
declare(strict_types=1);
namespace App\Entity\Inventory;
use App\Entity\Catalog\MeasurementUnit; use App\Entity\Materials\MaterialVariant; use App\Entity\Users\User; use App\Enum\Inventory\MovementType; use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity,ORM\Table(name:'inventory_movements')]
/** Hecho inmutable del libro de inventario; los saldos se derivan de estos movimientos. */
class InventoryMovement
{
 /** Identificador interno del movimiento. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null; /** Presentación cuyo saldo se afecta o reserva. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'material_variant_id',nullable:false,onDelete:'RESTRICT')] private MaterialVariant $variant;
 /** Lote afectado; obligatorio por dominio cuando la variante controla lotes. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'lot_id',onDelete:'RESTRICT')] private ?InventoryLot $lot=null; /** Unidad en la que se registró la cantidad. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'unit_id',nullable:false,onDelete:'RESTRICT')] private MeasurementUnit $unit;
 /** Usuario responsable de confirmar el hecho. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'responsible_user_id',nullable:false,onDelete:'RESTRICT')] private User $responsibleUser; /** Naturaleza de la entrada, salida, reserva o liberación. */ #[ORM\Column(name:'movement_type',length:30,enumType:MovementType::class)] private MovementType $type;
 /** Cantidad firmada: positiva para entradas/liberaciones y negativa para salidas/reservas. */ #[ORM\Column(type:Types::DECIMAL,precision:20,scale:6)] private string $quantity; /** Costo unitario histórico en MXN aplicable al movimiento. */ #[ORM\Column(name:'unit_cost_mxn',type:Types::DECIMAL,precision:19,scale:6,nullable:true)] private ?string $unitCostMxn=null;
 /** Tipo del documento origen, por ejemplo compra u orden de servicio. */ #[ORM\Column(name:'source_type',length:50)] private string $sourceType; /** Identificador opcional del documento origen. */ #[ORM\Column(name:'source_id',nullable:true)] private ?int $sourceId=null; /** Señala una recepción física todavía pendiente de factura. */ #[ORM\Column(name:'is_provisional_receipt')] private bool $isProvisionalReceipt=false;
 /** Usuario con permiso excepcional que autorizó dejar saldo negativo; normalmente es NULL. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'negative_stock_authorized_by',onDelete:'RESTRICT')] private ?User $negativeStockAuthorizedBy=null;
 /** Justificación obligatoria cuando existe una autorización excepcional de saldo negativo. */ #[ORM\Column(name:'negative_stock_reason',length:255,nullable:true)] private ?string $negativeStockReason=null;
 /** Momento UTC en que ocurrió el hecho físico. */ #[ORM\Column(name:'occurred_at',type:Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $occurredAt; /** Momento UTC en que se registró en PRINTFLOW. */ #[ORM\Column(name:'created_at',type:Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt;
 public function __construct(MaterialVariant $variant,MeasurementUnit $unit,User $user,MovementType $type,string $quantity,string $sourceType){$this->variant=$variant;$this->unit=$unit;$this->responsibleUser=$user;$this->type=$type;$this->quantity=$quantity;$this->sourceType=$sourceType;$this->occurredAt=$this->createdAt=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));}
}
