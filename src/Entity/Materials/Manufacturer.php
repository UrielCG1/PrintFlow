<?php
declare(strict_types=1);
namespace App\Entity\Materials;
use App\Entity\Common\Timestampable; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity,ORM\Table(name:'manufacturers'),ORM\HasLifecycleCallbacks]
/** Catálogo de fabricantes; es independiente del proveedor que comercializa el material. */
class Manufacturer { use Timestampable; /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null; /** Código técnico estable del fabricante. */ #[ORM\Column(length:40,unique:true)] private string $code; /** Razón o nombre reconocido del fabricante. */ #[ORM\Column(length:160,unique:true)] private string $name; /** Indica si está disponible para nuevas asignaciones. */ #[ORM\Column(name:'is_active')] private bool $isActive=true; public function __construct(string $code,string $name){$this->code=strtoupper(trim($code));$this->name=trim($name);$this->initializeTimestamps();} }
