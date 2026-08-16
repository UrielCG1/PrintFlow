<?php
declare(strict_types=1);
namespace App\Entity\Materials;
use App\Entity\Common\Timestampable; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity,ORM\Table(name:'adhesive_types'),ORM\HasLifecycleCallbacks]
/** Catálogo de tecnologías de adhesivo, por ejemplo permanente, removible o reposicionable. */
class AdhesiveType { use Timestampable; /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null; /** Código técnico estable del adhesivo. */ #[ORM\Column(length:40,unique:true)] private string $code; /** Nombre visible del tipo de adhesivo. */ #[ORM\Column(length:100,unique:true)] private string $name; /** Indica si puede asignarse a nuevas variantes. */ #[ORM\Column(name:'is_active')] private bool $isActive=true; public function __construct(string $code,string $name){$this->code=strtoupper(trim($code));$this->name=trim($name);$this->initializeTimestamps();} }
