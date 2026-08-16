<?php
declare(strict_types=1);

namespace App\Entity\Common;

use Doctrine\ORM\Mapping as ORM;

/** Número telefónico reutilizable, separado de la entidad que lo utiliza. */
#[ORM\Entity]
#[ORM\Table(name: 'phones')]
#[ORM\HasLifecycleCallbacks]
class Phone
{
    use Timestampable;
    /** Identificador interno. */ #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id=null;
    /** Tipo: LANDLINE, MOBILE, PERSONAL_MOBILE o FAX. */ #[ORM\Column(name:'phone_type',length:20)] private string $phoneType;
    /** Prefijo internacional, por defecto 52. */ #[ORM\Column(name:'country_code',length:5,options:['default'=>'52'])] private string $countryCode='52';
    /** Lada o clave de área. */ #[ORM\Column(name:'area_code',length:10,nullable:true)] private ?string $areaCode=null;
    /** Número local sin extensión. */ #[ORM\Column(length:30)] private string $number;
    /** Extensión del conmutador. */ #[ORM\Column(length:15,nullable:true)] private ?string $extension=null;
    /** Observaciones del número. */ #[ORM\Column(type:'text',nullable:true)] private ?string $notes=null;
    public function __construct(string $phoneType,string $number){$this->phoneType=strtoupper(trim($phoneType));$this->number=trim($number);$this->initializeTimestamps();}
    public function getId():?int{return $this->id;} public function getPhoneType():string{return $this->phoneType;}
    public function setPhoneType(string $v):self{$this->phoneType=strtoupper(trim($v));return $this;} public function getNumber():string{return $this->number;}
    public function setNumber(string $v):self{$this->number=trim($v);return $this;} public function getCountryCode():string{return $this->countryCode;}
    public function setCountryCode(string $v):self{$this->countryCode=trim($v);return $this;} public function getAreaCode():?string{return $this->areaCode;}
    public function setAreaCode(?string $v):self{$v=trim((string)$v);$this->areaCode=$v?:null;return $this;} public function getExtension():?string{return $this->extension;}
    public function setExtension(?string $v):self{$v=trim((string)$v);$this->extension=$v?:null;return $this;} public function getNotes():?string{return $this->notes;}
    public function setNotes(?string $v):self{$v=trim((string)$v);$this->notes=$v?:null;return $this;}
}
