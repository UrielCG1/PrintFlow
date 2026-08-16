<?php
declare(strict_types=1);
namespace App\Entity\Materials;
use App\Entity\Catalog\MeasurementUnit; use App\Entity\Common\Timestampable; use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity,ORM\Table(name:'material_variant_conversions'),ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name:'uniq_material_variant_conversion',columns:['material_variant_id','from_unit_id','to_unit_id'])]
/** Conversión contextual de una presentación, por ejemplo un rollo a 50 metros lineales. */
class MaterialVariantConversion
{
 use Timestampable; /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
 /** Presentación para la cual es válida la equivalencia. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'material_variant_id',nullable:false,onDelete:'CASCADE')] private MaterialVariant $variant;
 /** Unidad en la que se recibe la cantidad. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'from_unit_id',nullable:false,onDelete:'RESTRICT')] private MeasurementUnit $fromUnit;
 /** Unidad en la que se desea obtener la cantidad. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'to_unit_id',nullable:false,onDelete:'RESTRICT')] private MeasurementUnit $toUnit;
 /** Multiplicador: cantidad destino = cantidad origen × factor. */ #[ORM\Column(type:Types::DECIMAL,precision:24,scale:12)] private string $factor; /** Permite usar también la conversión inversa mediante 1/factor. */ #[ORM\Column(name:'is_bidirectional')] private bool $isBidirectional=true; /** Indica si la equivalencia puede seguir utilizándose. */ #[ORM\Column(name:'is_active')] private bool $isActive=true;
 public function __construct(MaterialVariant $variant,MeasurementUnit $from,MeasurementUnit $to,string $factor){if($from===$to||preg_match('/^(?:0\.(?:0*[1-9]\d*)|[1-9]\d*(?:\.\d+)?)$/D',$factor)!==1)throw new \InvalidArgumentException('Conversión inválida.');$this->variant=$variant;$this->fromUnit=$from;$this->toUnit=$to;$this->factor=$factor;$this->initializeTimestamps();}
}
