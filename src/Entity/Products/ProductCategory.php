<?php
declare(strict_types=1);
namespace App\Entity\Products;
use App\Entity\Common\Timestampable; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity,ORM\Table(name:'product_categories'),ORM\HasLifecycleCallbacks]
/** Jerarquía comercial independiente de las categorías de materias primas. */
class ProductCategory { use Timestampable; /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null; /** Categoría superior opcional; no puede formar ciclos. */ #[ORM\ManyToOne(targetEntity:self::class)] #[ORM\JoinColumn(name:'parent_id',onDelete:'RESTRICT')] private ?self $parent=null; /** Código comercial estable y único. */ #[ORM\Column(length:40,unique:true)] private string $code; /** Nombre visible de la categoría. */ #[ORM\Column(length:120)] private string $name; /** Indica si acepta productos nuevos. */ #[ORM\Column(name:'is_active')] private bool $isActive=true; public function __construct(string $code,string $name){$this->code=strtoupper(trim($code));$this->name=trim($name);$this->initializeTimestamps();} }
