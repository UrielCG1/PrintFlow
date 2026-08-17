<?php
declare(strict_types=1);
namespace App\Entity\Suppliers;
use App\Entity\Common\Contact; use App\Entity\Common\Timestampable; use Doctrine\ORM\Mapping as ORM;
/** Relación laboral entre una persona de contacto y un proveedor. */
#[ORM\Entity,ORM\Table(name:'supplier_contacts'),ORM\UniqueConstraint(name:'uniq_supplier_contact',columns:['supplier_id','contact_id']),ORM\HasLifecycleCallbacks]
class SupplierContact { use Timestampable;
    #[ORM\ManyToOne(targetEntity:SupplierBranch::class),ORM\JoinColumn(name:'supplier_branch_id',nullable:true,onDelete:'CASCADE')] private ?SupplierBranch $branch=null;
    /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
    /** Proveedor para el que trabaja la persona. */ #[ORM\ManyToOne(targetEntity:Supplier::class),ORM\JoinColumn(name:'supplier_id',nullable:false,onDelete:'CASCADE')] private Supplier $supplier;
    /** Datos personales reutilizables del contacto. */ #[ORM\ManyToOne(targetEntity:Contact::class),ORM\JoinColumn(name:'contact_id',nullable:false,onDelete:'RESTRICT')] private Contact $contact;
    /** Departamento o área. */ #[ORM\Column(length:120,nullable:true)] private ?string $department=null;
    /** Cargo dentro del proveedor. */ #[ORM\Column(length:120,nullable:true)] private ?string $position=null;
    /** Correo laboral específico de esta relación. */ #[ORM\Column(name:'business_email',length:180,nullable:true)] private ?string $businessEmail=null;
    /** Contacto principal del proveedor. */ #[ORM\Column(name:'is_primary',options:['default'=>false])] private bool $isPrimary=false;
    /** Indica que está autorizado para vender u ofrecer productos. */ #[ORM\Column(name:'can_sell_products',options:['default'=>true])] private bool $canSellProducts=true;
    /** Observaciones de la relación comercial. */ #[ORM\Column(type:'text',nullable:true)] private ?string $notes=null;
    /** Vigencia de la relación. */ #[ORM\Column(name:'is_active',options:['default'=>true])] private bool $isActive=true;
    public function __construct(Supplier $supplier,Contact $contact){$this->supplier=$supplier;$this->contact=$contact;$this->initializeTimestamps();}
    public function getId():?int{return $this->id;} public function getSupplier():Supplier{return $this->supplier;} public function getBranch():?SupplierBranch{return $this->branch;} public function setBranch(?SupplierBranch $v):self{$this->branch=$v;return $this;} public function getContact():Contact{return $this->contact;} public function getDepartment():?string{return $this->department;} public function getPosition():?string{return $this->position;} public function getBusinessEmail():?string{return $this->businessEmail;} public function isPrimary():bool{return $this->isPrimary;} public function canSellProducts():bool{return $this->canSellProducts;} public function getNotes():?string{return $this->notes;} public function isActive():bool{return $this->isActive;}
    public function setDepartment(?string $v):self{$v=trim((string)$v);$this->department=$v?:null;return $this;} public function setPosition(?string $v):self{$v=trim((string)$v);$this->position=$v?:null;return $this;}
    public function setBusinessEmail(?string $v):self{$v=trim((string)$v);$this->businessEmail=$v?strtolower($v):null;return $this;} public function setIsPrimary(bool $v):self{$this->isPrimary=$v;return $this;} public function setCanSellProducts(bool $v):self{$this->canSellProducts=$v;return $this;} public function setNotes(?string $v):self{$v=trim((string)$v);$this->notes=$v?:null;return $this;} public function setIsActive(bool $v):self{$this->isActive=$v;return $this;}
}
