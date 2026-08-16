<?php
declare(strict_types=1);
namespace App\Entity\Clients;
use App\Entity\Common\Address; use App\Entity\Common\Timestampable; use Doctrine\ORM\Mapping as ORM;
/** Asigna un domicilio reutilizable a una sucursal del cliente. */
#[ORM\Entity,ORM\Table(name:'client_branch_addresses'),ORM\UniqueConstraint(name:'uniq_branch_address_type',columns:['client_branch_id','address_id','address_type']),ORM\HasLifecycleCallbacks]
class ClientBranchAddress { use Timestampable;
    /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
    /** Sucursal que utiliza el domicilio. */ #[ORM\ManyToOne(targetEntity:ClientBranch::class),ORM\JoinColumn(name:'client_branch_id',nullable:false,onDelete:'CASCADE')] private ClientBranch $branch;
    /** Datos físicos compartidos del domicilio. */ #[ORM\ManyToOne(targetEntity:Address::class),ORM\JoinColumn(name:'address_id',nullable:false,onDelete:'RESTRICT')] private Address $address;
    /** Uso: FISCAL, COMMERCIAL o DELIVERY. */ #[ORM\Column(name:'address_type',length:20)] private string $addressType;
    /** Zona logística; solo corresponde a domicilios DELIVERY. */ #[ORM\ManyToOne(targetEntity:DeliveryZone::class),ORM\JoinColumn(name:'delivery_zone_id',nullable:true,onDelete:'RESTRICT')] private ?DeliveryZone $deliveryZone=null;
    /** Costo pactado de entrega; solo corresponde a DELIVERY. */ #[ORM\Column(name:'delivery_cost',type:'decimal',precision:12,scale:2,nullable:true)] private ?string $deliveryCost=null;
    /** Marca el domicilio preferido de este tipo. */ #[ORM\Column(name:'is_default',options:['default'=>false])] private bool $isDefault=false;
    /** Indica si la asignación está vigente. */ #[ORM\Column(name:'is_active',options:['default'=>true])] private bool $isActive=true;
    public function __construct(ClientBranch $branch,Address $address,string $type){$this->branch=$branch;$this->address=$address;$this->addressType=strtoupper(trim($type));$this->initializeTimestamps();}
    public function getId():?int{return $this->id;} public function getBranch():ClientBranch{return $this->branch;} public function getAddress():Address{return $this->address;}
    public function getAddressType():string{return $this->addressType;} public function setAddressType(string $v):self{$this->addressType=strtoupper(trim($v));return $this;}
    public function setDeliveryZone(?DeliveryZone $v):self{$this->deliveryZone=$v;return $this;} public function setDeliveryCost(?string $v):self{$this->deliveryCost=$v===null?null:number_format((float)$v,2,'.','');return $this;}
    public function setIsDefault(bool $v):self{$this->isDefault=$v;return $this;} public function setIsActive(bool $v):self{$this->isActive=$v;return $this;}
}
