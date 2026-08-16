<?php
declare(strict_types=1);
namespace App\Entity\Suppliers;
use App\Entity\Clients\DeliveryZone; use App\Entity\Common\Address; use App\Entity\Common\Timestampable; use Doctrine\ORM\Mapping as ORM;
/** Asignación de un domicilio reutilizable a un proveedor. */
#[ORM\Entity,ORM\Table(name:'supplier_addresses'),ORM\UniqueConstraint(name:'uniq_supplier_address_type',columns:['supplier_id','address_id','address_type']),ORM\HasLifecycleCallbacks]
class SupplierAddress { use Timestampable;
    /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
    /** Proveedor que utiliza el domicilio. */ #[ORM\ManyToOne(targetEntity:Supplier::class),ORM\JoinColumn(name:'supplier_id',nullable:false,onDelete:'CASCADE')] private Supplier $supplier;
    /** Domicilio físico reutilizable. */ #[ORM\ManyToOne(targetEntity:Address::class),ORM\JoinColumn(name:'address_id',nullable:false,onDelete:'RESTRICT')] private Address $address;
    /** Uso: FISCAL, COMMERCIAL o DELIVERY. */ #[ORM\Column(name:'address_type',length:20)] private string $addressType;
    /** Zona logística cuando el uso es DELIVERY. */ #[ORM\ManyToOne(targetEntity:DeliveryZone::class),ORM\JoinColumn(name:'delivery_zone_id',nullable:true,onDelete:'RESTRICT')] private ?DeliveryZone $deliveryZone=null;
    /** Costo de entrega cuando el uso es DELIVERY. */ #[ORM\Column(name:'delivery_cost',type:'decimal',precision:12,scale:2,nullable:true)] private ?string $deliveryCost=null;
    /** Domicilio preferido para ese uso. */ #[ORM\Column(name:'is_default',options:['default'=>false])] private bool $isDefault=false;
    /** Vigencia de la asignación. */ #[ORM\Column(name:'is_active',options:['default'=>true])] private bool $isActive=true;
    public function __construct(Supplier $supplier,Address $address,string $type){$this->supplier=$supplier;$this->address=$address;$this->addressType=strtoupper(trim($type));$this->initializeTimestamps();}
    public function getId():?int{return $this->id;} public function getSupplier():Supplier{return $this->supplier;} public function getAddress():Address{return $this->address;} public function getAddressType():string{return $this->addressType;}
    public function setAddressType(string $v):self{$this->addressType=strtoupper(trim($v));return $this;} public function setDeliveryZone(?DeliveryZone $v):self{$this->deliveryZone=$v;return $this;} public function setDeliveryCost(?string $v):self{$this->deliveryCost=$v===null?null:number_format((float)$v,2,'.','');return $this;} public function setIsDefault(bool $v):self{$this->isDefault=$v;return $this;} public function setIsActive(bool $v):self{$this->isActive=$v;return $this;}
}
