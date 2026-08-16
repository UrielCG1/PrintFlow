<?php
declare(strict_types=1);
namespace App\Entity\Suppliers;
use App\Entity\Common\Phone; use App\Entity\Common\Timestampable; use Doctrine\ORM\Mapping as ORM;
/** Asigna un teléfono reutilizable directamente a un proveedor. */
#[ORM\Entity,ORM\Table(name:'supplier_phones'),ORM\UniqueConstraint(name:'uniq_supplier_phone',columns:['supplier_id','phone_id']),ORM\HasLifecycleCallbacks]
class SupplierPhone { use Timestampable;
    /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
    /** Proveedor que utiliza el teléfono. */ #[ORM\ManyToOne(targetEntity:Supplier::class),ORM\JoinColumn(name:'supplier_id',nullable:false,onDelete:'CASCADE')] private Supplier $supplier;
    /** Número telefónico reutilizable. */ #[ORM\ManyToOne(targetEntity:Phone::class),ORM\JoinColumn(name:'phone_id',nullable:false,onDelete:'RESTRICT')] private Phone $phone;
    /** Etiqueta visible. */ #[ORM\Column(length:80,nullable:true)] private ?string $label=null;
    /** Número principal. */ #[ORM\Column(name:'is_primary',options:['default'=>false])] private bool $isPrimary=false;
    /** Vigencia de la asignación. */ #[ORM\Column(name:'is_active',options:['default'=>true])] private bool $isActive=true;
    public function __construct(Supplier $supplier,Phone $phone){$this->supplier=$supplier;$this->phone=$phone;$this->initializeTimestamps();}
    public function getId():?int{return $this->id;} public function setLabel(?string $v):self{$v=trim((string)$v);$this->label=$v?:null;return $this;} public function setIsPrimary(bool $v):self{$this->isPrimary=$v;return $this;} public function setIsActive(bool $v):self{$this->isActive=$v;return $this;}
}
