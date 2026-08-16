<?php
declare(strict_types=1);
namespace App\Entity\Common;
use Doctrine\ORM\Mapping as ORM;
/** Asigna un teléfono reutilizable a una persona de contacto. */
#[ORM\Entity,ORM\Table(name:'contact_phones'),ORM\UniqueConstraint(name:'uniq_contact_phone',columns:['contact_id','phone_id']),ORM\HasLifecycleCallbacks]
class ContactPhone { use Timestampable;
    /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
    /** Persona que utiliza el teléfono. */ #[ORM\ManyToOne(targetEntity:Contact::class),ORM\JoinColumn(name:'contact_id',nullable:false,onDelete:'CASCADE')] private Contact $contact;
    /** Número telefónico compartido. */ #[ORM\ManyToOne(targetEntity:Phone::class,cascade:['persist']),ORM\JoinColumn(name:'phone_id',nullable:false,onDelete:'RESTRICT')] private Phone $phone;
    /** Etiqueta visible, por ejemplo Oficina o Personal. */ #[ORM\Column(length:80,nullable:true)] private ?string $label=null;
    /** Número preferido para contactar a la persona. */ #[ORM\Column(name:'is_primary',options:['default'=>false])] private bool $isPrimary=false;
    /** Vigencia de la asignación. */ #[ORM\Column(name:'is_active',options:['default'=>true])] private bool $isActive=true;
    public function __construct(Contact $contact,Phone $phone){$this->contact=$contact;$this->phone=$phone;$this->initializeTimestamps();}
    public function getId():?int{return $this->id;} public function getPhone():Phone{return $this->phone;} public function isPrimary():bool{return $this->isPrimary;} public function isActive():bool{return $this->isActive;} public function setLabel(?string $v):self{$v=trim((string)$v);$this->label=$v?:null;return $this;} public function setIsPrimary(bool $v):self{$this->isPrimary=$v;return $this;} public function setIsActive(bool $v):self{$this->isActive=$v;return $this;}
}
