<?php
declare(strict_types=1);
namespace App\Entity\Clients;
use App\Entity\Common\Contact; use App\Repository\Clients\ClientContactRepository; use Doctrine\ORM\Mapping as ORM;
/** Relación laboral entre una persona de contacto y un cliente o una de sus sucursales. */
#[ORM\Entity(repositoryClass:ClientContactRepository::class),ORM\Table(name:'client_contacts')]
#[ORM\Index(name:'idx_client_contacts_client_active',columns:['client_id','is_active']),ORM\Index(name:'idx_client_contacts_client_primary',columns:['client_id','is_primary'])]
#[ORM\UniqueConstraint(name:'uniq_client_contacts_primary_active',columns:['primary_client_id']),ORM\HasLifecycleCallbacks]
class ClientContact {
    /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
    /** Cliente relacionado. */ #[ORM\ManyToOne(targetEntity:Client::class),ORM\JoinColumn(name:'client_id',nullable:false,onDelete:'RESTRICT')] private Client $client;
    /** Persona normalizada; única fuente de nombre, cumpleaños, horario y teléfonos. */ #[ORM\ManyToOne(targetEntity:Contact::class,cascade:['persist']),ORM\JoinColumn(name:'contact_id',nullable:false,onDelete:'RESTRICT')] private Contact $contact;
    /** Sucursal donde atiende. */ #[ORM\ManyToOne(targetEntity:ClientBranch::class),ORM\JoinColumn(name:'client_branch_id',nullable:true,onDelete:'RESTRICT')] private ?ClientBranch $branch=null;
    /** Departamento o área. */ #[ORM\Column(length:120,nullable:true)] private ?string $department=null;
    /** Cargo dentro del cliente. */ #[ORM\Column(name:'job_title',length:120,nullable:true)] private ?string $jobTitle=null;
    /** Correo laboral propio de esta relación. */ #[ORM\Column(name:'business_email',length:180,nullable:true)] private ?string $businessEmail=null;
    /** Contacto principal. */ #[ORM\Column(name:'is_primary',options:['default'=>false])] private bool $isPrimary=false;
    /** Puede solicitar productos. */ #[ORM\Column(name:'can_request_products',options:['default'=>true])] private bool $canRequestProducts=true;
    #[ORM\Column(name:'primary_client_id',type:'integer',nullable:true,insertable:false,updatable:false,generated:'ALWAYS',columnDefinition:'INT GENERATED ALWAYS AS (CASE WHEN is_active = 1 AND is_primary = 1 THEN client_id ELSE NULL END) STORED')] private ?int $primaryClientId=null;
    /** Vigencia de la relación. */ #[ORM\Column(name:'is_active',options:['default'=>true])] private bool $isActive=true;
    #[ORM\Column(name:'created_at',type:'datetime_immutable')] private \DateTimeImmutable $createdAt; #[ORM\Column(name:'updated_at',type:'datetime_immutable')] private \DateTimeImmutable $updatedAt;
    public function __construct(Client $client,Contact $contact){$this->client=$client;$this->contact=$contact;$this->createdAt=$this->updatedAt=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));}
    public function getId():?int{return $this->id;} public function getClient():Client{return $this->client;} public function getContact():Contact{return $this->contact;}
    public function getBranch():?ClientBranch{return $this->branch;} public function setBranch(?ClientBranch $v):self{$this->branch=$v;return $this;} public function getDepartment():?string{return $this->department;} public function setDepartment(?string $v):self{$v=trim((string)$v);$this->department=$v?:null;return $this;}
    public function getFullName():string{return $this->contact->getFullName();} public function setFullName(string $v):self{$parts=preg_split('/\s+/',trim($v),2)?:[];$this->contact->setFirstName($parts[0]??'')->setLastName($parts[1]??null);return $this;}
    public function getJobTitle():?string{return $this->jobTitle;} public function setJobTitle(?string $v):self{$v=trim((string)$v);$this->jobTitle=$v?:null;return $this;}
    public function getEmail():?string{return $this->businessEmail;} public function setEmail(?string $v):self{$v=trim((string)$v);$this->businessEmail=$v?strtolower($v):null;return $this;}
    public function getPhone():?string{return $this->contact->getPrimaryPhone();} public function setPhone(?string $v):self{$this->contact->setPrimaryPhone($v);return $this;}
    public function getWorkSchedule():?string{return $this->contact->getWorkHours();} public function setWorkSchedule(?string $v):self{$this->contact->setWorkHours($v);return $this;}
    public function canRequestProducts():bool{return $this->canRequestProducts;} public function setCanRequestProducts(bool $v):self{$this->canRequestProducts=$v;return $this;}
    public function isPrimary():bool{return $this->isPrimary;} public function setIsPrimary(bool $v):self{$this->isPrimary=$v;return $this;} public function isActive():bool{return $this->isActive;} public function setIsActive(bool $v):self{$this->isActive=$v;if(!$v){$this->isPrimary=false;}return $this;}
    #[ORM\PreUpdate] public function updateTimestamp():void{$this->updatedAt=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));}
}
