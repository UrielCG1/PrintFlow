<?php
declare(strict_types=1);
namespace App\Entity\Common;
use Doctrine\ORM\Mapping as ORM;
/** Asigna a una persona de contacto uno de sus domicilios personales o laborales. */
#[ORM\Entity,ORM\Table(name:'contact_addresses'),ORM\UniqueConstraint(name:'uniq_contact_address_type',columns:['contact_id','address_id','address_type']),ORM\HasLifecycleCallbacks]
class ContactAddress { use Timestampable;
    /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
    /** Persona propietaria del dato. */ #[ORM\ManyToOne(targetEntity:Contact::class),ORM\JoinColumn(name:'contact_id',nullable:false,onDelete:'CASCADE')] private Contact $contact;
    /** Domicilio reutilizable. */ #[ORM\ManyToOne(targetEntity:Address::class),ORM\JoinColumn(name:'address_id',nullable:false,onDelete:'RESTRICT')] private Address $address;
    /** Uso PERSONAL o WORK. */ #[ORM\Column(name:'address_type',length:20)] private string $addressType;
    /** Domicilio principal de la persona. */ #[ORM\Column(name:'is_primary',options:['default'=>false])] private bool $isPrimary=false;
    /** Vigencia de la asignación. */ #[ORM\Column(name:'is_active',options:['default'=>true])] private bool $isActive=true;
    public function __construct(Contact $contact,Address $address,string $type){$this->contact=$contact;$this->address=$address;$this->addressType=strtoupper(trim($type));$this->initializeTimestamps();}
    public function getId():?int{return $this->id;} public function setIsPrimary(bool $v):self{$this->isPrimary=$v;return $this;} public function setIsActive(bool $v):self{$this->isActive=$v;return $this;}
}
