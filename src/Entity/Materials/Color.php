<?php
declare(strict_types=1);
namespace App\Entity\Materials;
use App\Entity\Common\Timestampable; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity,ORM\Table(name:'colors'),ORM\HasLifecycleCallbacks]
/** Catálogo del color físico del insumo; no representa los colores de una imagen impresa. */
class Color { use Timestampable; /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null; /** Código estable del color. */ #[ORM\Column(length:40,unique:true)] private string $code; /** Nombre visible, por ejemplo blanco, cian o transparente. */ #[ORM\Column(length:100,unique:true)] private string $name; /** Representación hexadecimal opcional para interfaces; no es una especificación colorimétrica. */ #[ORM\Column(name:'hex_value',length:7,nullable:true)] private ?string $hexValue=null; /** Indica si puede asignarse a nuevas variantes. */ #[ORM\Column(name:'is_active')] private bool $isActive=true; public function __construct(string $code,string $name){$this->code=strtoupper(trim($code));$this->name=trim($name);$this->initializeTimestamps();} }
