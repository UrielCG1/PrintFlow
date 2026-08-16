<?php
declare(strict_types=1);
namespace App\Entity\Common;
use App\Entity\Clients\Client; use App\Entity\Suppliers\Supplier; use Doctrine\ORM\Mapping as ORM;
/** Configuración fiscal y de facturación de un cliente o proveedor sin duplicar RFC, razón social ni domicilio. */
#[ORM\Entity,ORM\Table(name:'tax_data'),ORM\HasLifecycleCallbacks]
class TaxData { use Timestampable;
    /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
    /** Cliente propietario; excluyente con supplier. */ #[ORM\ManyToOne(targetEntity:Client::class),ORM\JoinColumn(name:'client_id',nullable:true,onDelete:'CASCADE')] private ?Client $client=null;
    /** Proveedor propietario; excluyente con client. */ #[ORM\ManyToOne(targetEntity:Supplier::class),ORM\JoinColumn(name:'supplier_id',nullable:true,onDelete:'CASCADE')] private ?Supplier $supplier=null;
    /** Domicilio fiscal reutilizable. */ #[ORM\ManyToOne(targetEntity:Address::class),ORM\JoinColumn(name:'fiscal_address_id',nullable:false,onDelete:'RESTRICT')] private Address $fiscalAddress;
    /** Clave SAT del régimen fiscal. */ #[ORM\Column(name:'tax_regime_code',length:3)] private string $taxRegimeCode;
    /** Correo al que se envían CFDI. */ #[ORM\Column(name:'billing_email',length:180)] private string $billingEmail;
    /** Uso CFDI predeterminado. */ #[ORM\Column(name:'cfdi_use_code',length:10)] private string $cfdiUseCode;
    /** Configuración fiscal predeterminada del propietario. */ #[ORM\Column(name:'is_default',options:['default'=>false])] private bool $isDefault=false;
    /** Vigencia de la configuración. */ #[ORM\Column(name:'is_active',options:['default'=>true])] private bool $isActive=true;
    private function __construct(Address $address,string $regime,string $email,string $cfdi){$this->fiscalAddress=$address;$this->taxRegimeCode=trim($regime);$this->billingEmail=strtolower(trim($email));$this->cfdiUseCode=strtoupper(trim($cfdi));$this->initializeTimestamps();}
    public static function forClient(Client $owner,Address $address,string $regime,string $email,string $cfdi):self{$self=new self($address,$regime,$email,$cfdi);$self->client=$owner;return $self;}
    public static function forSupplier(Supplier $owner,Address $address,string $regime,string $email,string $cfdi):self{$self=new self($address,$regime,$email,$cfdi);$self->supplier=$owner;return $self;}
    public function getId():?int{return $this->id;} public function setIsDefault(bool $v):self{$this->isDefault=$v;return $this;} public function setIsActive(bool $v):self{$this->isActive=$v;return $this;}
}
