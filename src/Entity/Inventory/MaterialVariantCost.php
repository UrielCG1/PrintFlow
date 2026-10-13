<?php
declare(strict_types=1);
namespace App\Entity\Inventory;
use App\Entity\Materials\MaterialVariant; use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
use App\Repository\Inventory\MaterialVariantCostRepository;
use Brick\Math\BigDecimal;
#[ORM\Entity(repositoryClass:MaterialVariantCostRepository::class),ORM\Table(name:'material_variant_costs')]
/** Proyección bloqueable del saldo, valor y costo promedio móvil actual de una variante. */
class MaterialVariantCost
{
 /** Variante y clave primaria de la proyección. */ #[ORM\Id,ORM\OneToOne] #[ORM\JoinColumn(name:'material_variant_id',onDelete:'RESTRICT')] private MaterialVariant $variant;
 /** Existencia física actual en su unidad de inventario. */ #[ORM\Column(name:'on_hand_quantity',type:Types::DECIMAL,precision:20,scale:6)] private string $onHandQuantity='0.000000';
 /** Cantidad físicamente existente que no se puede usar por condición, caducidad o revisión. */ #[ORM\Column(name:'blocked_quantity',type:Types::DECIMAL,precision:20,scale:6)] private string $blockedQuantity='0.000000';
 /** Cantidad apartada para órdenes pendientes; no se descuenta del físico. */ #[ORM\Column(name:'committed_quantity',type:Types::DECIMAL,precision:20,scale:6)] private string $committedQuantity='0.000000';
 /** Valor contable total actual expresado en MXN. */ #[ORM\Column(name:'inventory_value_mxn',type:Types::DECIMAL,precision:19,scale:6)] private string $inventoryValueMxn='0.000000';
 /** Costo promedio móvil actual por unidad de inventario, en MXN. */ #[ORM\Column(name:'moving_average_cost_mxn',type:Types::DECIMAL,precision:19,scale:6)] private string $movingAverageCostMxn='0.000000';
 /** Instante UTC de la última actualización transaccional. */ #[ORM\Column(name:'updated_at',type:Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $updatedAt;
 public function __construct(MaterialVariant $variant){$this->variant=$variant;$this->updatedAt=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));}
 public function getVariant():MaterialVariant{return $this->variant;}
 public function getOnHandQuantity():string{return $this->onHandQuantity;}
 public function getBlockedQuantity():string{return $this->blockedQuantity;}
 public function getCommittedQuantity():string{return $this->committedQuantity;}
 public function getAvailableQuantity():string{return BigDecimal::of($this->onHandQuantity)->minus($this->blockedQuantity)->minus($this->committedQuantity)->toScale(6)->__toString();}
 public function increaseOnHand(string $quantity):void{$this->onHandQuantity=BigDecimal::of($this->onHandQuantity)->plus(self::positiveDecimal($quantity))->toScale(6)->__toString();$this->touchBalance();}
 public function commit(string $quantity):void
 {
  $quantity=self::positiveDecimal($quantity);
  $available=BigDecimal::of($this->getAvailableQuantity());
  if(BigDecimal::of($quantity)->isGreaterThan($available))throw new \DomainException('El apartado supera el saldo utilizable de la variante.');
  $this->committedQuantity=BigDecimal::of($this->committedQuantity)->plus($quantity)->toScale(6)->__toString();$this->touchBalance();
 }
 public function releaseCommitment(string $quantity):void
 {
  $quantity=self::positiveDecimal($quantity);
  $committed=BigDecimal::of($this->committedQuantity);
  if(BigDecimal::of($quantity)->isGreaterThan($committed))throw new \DomainException('La liberación supera el material comprometido.');
  $this->committedQuantity=$committed->minus($quantity)->toScale(6)->__toString();$this->touchBalance();
 }
 public function consume(string $quantity,string $committedQuantityToRelease='0'):void
 {
  $quantity=self::positiveDecimal($quantity);
  $release=trim(str_replace(',','.',$committedQuantityToRelease));
  if(preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D',$release)!==1)throw new \InvalidArgumentException('La liberación del apartado no tiene un formato válido.');
  $release=BigDecimal::of($release)->toScale(6)->__toString();
  $onHand=BigDecimal::of($this->onHandQuantity);
  $committed=BigDecimal::of($this->committedQuantity);
  if(BigDecimal::of($quantity)->isGreaterThan($onHand))throw new \DomainException('El consumo supera la existencia física registrada para la variante.');
  if(BigDecimal::of($release)->isGreaterThan(BigDecimal::of($quantity)))throw new \InvalidArgumentException('La liberación del apartado no puede superar el consumo físico.');
  if(BigDecimal::of($release)->isGreaterThan($committed))throw new \DomainException('La liberación del apartado supera el saldo pendiente de uso.');
  $this->onHandQuantity=$onHand->minus($quantity)->toScale(6)->__toString();
  $this->committedQuantity=$committed->minus($release)->toScale(6)->__toString();
  $this->touchBalance();
 }
 /** Ajusta el físico después de un conteo confirmado sin alterar apartados o bloqueos. */
 public function applyCountAdjustment(string $signedQuantity):void
 {
  $signedQuantity=trim(str_replace(',','.',$signedQuantity));
  if(preg_match('/^-?(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D',$signedQuantity)!==1||BigDecimal::of($signedQuantity)->isZero())throw new \InvalidArgumentException('La diferencia de conteo debe ser distinta de cero y tener máximo seis decimales.');
  $next=BigDecimal::of($this->onHandQuantity)->plus($signedQuantity);
  $protected=BigDecimal::of($this->blockedQuantity)->plus($this->committedQuantity);
  if($next->isLessThan($protected))throw new \DomainException('El conteo dejaría menos existencia física que la bloqueada o comprometida. Reconcilia esos apartados antes de ajustar.');
  $this->onHandQuantity=$next->toScale(6)->__toString();
  $this->touchBalance();
 }
 private function touchBalance():void{$this->updatedAt=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));}
 private static function positiveDecimal(string $value):string{$value=trim(str_replace(',','.',$value));if(preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D',$value)!==1||BigDecimal::of($value)->isLessThanOrEqualTo(BigDecimal::zero()))throw new \InvalidArgumentException('La cantidad física debe ser mayor que cero y tener máximo seis decimales.');return BigDecimal::of($value)->toScale(6)->__toString();}
}
