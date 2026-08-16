<?php
declare(strict_types=1);
namespace App\Entity\Clients;
use App\Entity\Common\Timestampable; use Doctrine\ORM\Mapping as ORM;
/** Sucursal o establecimiento operativo perteneciente a un cliente. */
#[ORM\Entity,ORM\Table(name:'client_branches'),ORM\UniqueConstraint(name:'uniq_client_branch_code',columns:['client_id','code']),ORM\HasLifecycleCallbacks]
class ClientBranch { use Timestampable;
    /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
    /** Cliente propietario de la sucursal. */ #[ORM\ManyToOne(targetEntity:Client::class),ORM\JoinColumn(name:'client_id',nullable:false,onDelete:'RESTRICT')] private Client $client;
    /** Código corto único dentro del cliente. */ #[ORM\Column(length:40)] private string $code;
    /** Nombre visible de la sucursal. */ #[ORM\Column(length:160)] private string $name;
    /** Correo general de la sucursal. */ #[ORM\Column(length:180,nullable:true)] private ?string $email=null;
    /** Observaciones. */ #[ORM\Column(type:'text',nullable:true)] private ?string $notes=null;
    /** Identifica la casa matriz o sucursal principal. */ #[ORM\Column(name:'is_main',options:['default'=>false])] private bool $isMain=false;
    /** Permite utilizar la sucursal en operaciones nuevas. */ #[ORM\Column(name:'is_active',options:['default'=>true])] private bool $isActive=true;
    public function __construct(Client $client,string $code,string $name){$this->client=$client;$this->code=strtoupper(trim($code));$this->name=trim($name);$this->initializeTimestamps();}
    public function getId():?int{return $this->id;} public function getClient():Client{return $this->client;} public function getCode():string{return $this->code;} public function setCode(string $v):self{$this->code=strtoupper(trim($v));return $this;}
    public function getName():string{return $this->name;} public function setName(string $v):self{$this->name=trim($v);return $this;} public function getEmail():?string{return $this->email;} public function setEmail(?string $v):self{$v=trim((string)$v);$this->email=$v?strtolower($v):null;return $this;}
    public function getNotes():?string{return $this->notes;} public function setNotes(?string $v):self{$v=trim((string)$v);$this->notes=$v?:null;return $this;} public function isMain():bool{return $this->isMain;} public function setIsMain(bool $v):self{$this->isMain=$v;return $this;} public function isActive():bool{return $this->isActive;} public function setIsActive(bool $v):self{$this->isActive=$v;return $this;}
}
