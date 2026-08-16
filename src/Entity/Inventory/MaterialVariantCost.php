<?php
declare(strict_types=1);
namespace App\Entity\Inventory;
use App\Entity\Materials\MaterialVariant; use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity,ORM\Table(name:'material_variant_costs')]
/** Proyección bloqueable del saldo, valor y costo promedio móvil actual de una variante. */
class MaterialVariantCost
{
 /** Variante y clave primaria de la proyección. */ #[ORM\Id,ORM\OneToOne] #[ORM\JoinColumn(name:'material_variant_id',nullable:false,onDelete:'RESTRICT')] private MaterialVariant $variant;
 /** Existencia física actual en su unidad de inventario. */ #[ORM\Column(name:'on_hand_quantity',type:Types::DECIMAL,precision:20,scale:6)] private string $onHandQuantity='0.000000';
 /** Valor contable total actual expresado en MXN. */ #[ORM\Column(name:'inventory_value_mxn',type:Types::DECIMAL,precision:19,scale:6)] private string $inventoryValueMxn='0.000000';
 /** Costo promedio móvil actual por unidad de inventario, en MXN. */ #[ORM\Column(name:'moving_average_cost_mxn',type:Types::DECIMAL,precision:19,scale:6)] private string $movingAverageCostMxn='0.000000';
 /** Instante UTC de la última actualización transaccional. */ #[ORM\Column(name:'updated_at',type:Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $updatedAt;
 public function __construct(MaterialVariant $variant){$this->variant=$variant;$this->updatedAt=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));}
}
