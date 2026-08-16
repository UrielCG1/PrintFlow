<?php
declare(strict_types=1);
namespace App\Entity\Materials;
use App\Entity\Common\Timestampable; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity,ORM\Table(name:'finishes'),ORM\HasLifecycleCallbacks]
/** Catálogo de apariencia o terminación superficial, como mate, brillante o satinado. */
class Finish { use Timestampable; /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null; /** Código técnico estable del acabado. */ #[ORM\Column(length:40,unique:true)] private string $code; /** Nombre visible del acabado. */ #[ORM\Column(length:100,unique:true)] private string $name; /** Indica si puede utilizarse en nuevas variantes. */ #[ORM\Column(name:'is_active')] private bool $isActive=true; public function __construct(string $code,string $name){$this->code=strtoupper(trim($code));$this->name=trim($name);$this->initializeTimestamps();} }
