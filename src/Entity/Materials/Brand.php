<?php
declare(strict_types=1);
namespace App\Entity\Materials;
use App\Entity\Common\Timestampable;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity,ORM\Table(name:'brands'),ORM\HasLifecycleCallbacks]
/** Catálogo de marcas comerciales asociables a presentaciones de materiales. */
class Brand { use Timestampable; /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null; /** Código estable usado en integraciones y búsquedas. */ #[ORM\Column(length:40,unique:true)] private string $code; /** Nombre comercial visible de la marca. */ #[ORM\Column(length:120,unique:true)] private string $name; /** Indica si puede asignarse a nuevas variantes. */ #[ORM\Column(name:'is_active')] private bool $isActive=true; public function __construct(string $code,string $name){$this->code=strtoupper(trim($code));$this->name=trim($name);$this->initializeTimestamps();} public function getId():?int{return $this->id;} public function getName():string{return $this->name;} }
