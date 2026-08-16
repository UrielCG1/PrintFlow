<?php
declare(strict_types=1);

namespace App\Entity\Common;

use Doctrine\ORM\Mapping as ORM;

/** Persona de contacto reutilizable por clientes y proveedores. */
#[ORM\Entity]
#[ORM\Table(name:'contacts')]
#[ORM\HasLifecycleCallbacks]
class Contact
{
    use Timestampable;
    /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
    /** Nombre o nombres. */ #[ORM\Column(name:'first_name',length:100)] private string $firstName;
    /** Apellidos. */ #[ORM\Column(name:'last_name',length:120,nullable:true)] private ?string $lastName=null;
    /** Correo personal, no el asignado por una empresa. */ #[ORM\Column(name:'personal_email',length:180,nullable:true)] private ?string $personalEmail=null;
    /** Fecha de cumpleaños. */ #[ORM\Column(name:'birth_date',type:'date_immutable',nullable:true)] private ?\DateTimeImmutable $birthDate=null;
    /** Días habituales de atención, por ejemplo Lunes a Viernes. */ #[ORM\Column(name:'work_days',length:100,nullable:true)] private ?string $workDays=null;
    /** Horario laboral habitual, por ejemplo 09:00 a 18:00. */ #[ORM\Column(name:'work_hours',length:160,nullable:true)] private ?string $workHours=null;
    /** Observaciones generales. */ #[ORM\Column(type:'text',nullable:true)] private ?string $notes=null;
    /** Indica si puede asociarse a nuevos registros. */ #[ORM\Column(name:'is_active',options:['default'=>true])] private bool $isActive=true;
    public function __construct(string $firstName){$this->firstName=trim($firstName);$this->initializeTimestamps();}
    public function getId():?int{return $this->id;} public function getFirstName():string{return $this->firstName;} public function setFirstName(string $v):self{$this->firstName=trim($v);return $this;}
    public function getLastName():?string{return $this->lastName;} public function setLastName(?string $v):self{$v=trim((string)$v);$this->lastName=$v?:null;return $this;}
    public function getFullName():string{return trim($this->firstName.' '.($this->lastName??''));} public function getPersonalEmail():?string{return $this->personalEmail;}
    public function setPersonalEmail(?string $v):self{$v=trim((string)$v);$this->personalEmail=$v?strtolower($v):null;return $this;}
    public function getBirthDate():?\DateTimeImmutable{return $this->birthDate;} public function setBirthDate(?\DateTimeImmutable $v):self{$this->birthDate=$v;return $this;}
    public function getWorkDays():?string{return $this->workDays;} public function setWorkDays(?string $v):self{$v=trim((string)$v);$this->workDays=$v?:null;return $this;}
    public function getWorkHours():?string{return $this->workHours;} public function setWorkHours(?string $v):self{$v=trim((string)$v);$this->workHours=$v?:null;return $this;}
    public function getNotes():?string{return $this->notes;} public function setNotes(?string $v):self{$v=trim((string)$v);$this->notes=$v?:null;return $this;}
    public function isActive():bool{return $this->isActive;} public function setIsActive(bool $v):self{$this->isActive=$v;return $this;}
}
