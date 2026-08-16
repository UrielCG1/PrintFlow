<?php
declare(strict_types=1);
namespace App\Entity\Products;
use App\Entity\Catalog\MeasurementUnit; use App\Entity\Common\Timestampable; use App\Entity\Materials\Material; use App\Entity\Materials\MaterialVariant; use App\Enum\Production\CalculationMethod; use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity,ORM\Table(name:'bill_of_material_items'),ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name:'uniq_bom_product_sequence',columns:['product_id','sequence'])]
/** Renglón de la receta de un producto y regla para calcular su consumo planeado. */
class BillOfMaterialItem
{
 use Timestampable; /** Identificador interno del renglón. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null; /** Producto al que pertenece la receta. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'product_id',nullable:false,onDelete:'RESTRICT')] private Product $product;
 /** Material general cuando producción puede elegir una variante compatible. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'material_id',onDelete:'RESTRICT')] private ?Material $material=null; /** Variante exacta cuando la receta no permite sustitución. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'material_variant_id',onDelete:'RESTRICT')] private ?MaterialVariant $variant=null;
 /** Unidad en la que el método devuelve el consumo. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'measurement_unit_id',nullable:false,onDelete:'RESTRICT')] private MeasurementUnit $unit;
 /** Coeficiente base utilizado por el cálculo. */ #[ORM\Column(type:Types::DECIMAL,precision:20,scale:6)] private string $quantity; /** Desperdicio planeado porcentual de este renglón. */ #[ORM\Column(name:'waste_percentage',type:Types::DECIMAL,precision:7,scale:4)] private string $wastePercentage='0.0000';
 /** Algoritmo predefinido que calcula el consumo. */ #[ORM\Column(name:'calculation_method',length:30,enumType:CalculationMethod::class)] private CalculationMethod $calculationMethod; /** Versión del algoritmo, necesaria para reproducir cálculos históricos. */ #[ORM\Column(name:'calculation_method_version')] private int $calculationMethodVersion=1;
 /** Parámetros validados del algoritmo; nunca contiene código ejecutable. */ #[ORM\Column(name:'calculation_parameters',type:Types::JSON,nullable:true)] private ?array $calculationParameters=null; /** Posición del material dentro de la receta. */ #[ORM\Column] private int $sequence; /** Indica si el renglón participa en cálculos nuevos. */ #[ORM\Column(name:'is_active')] private bool $isActive=true;
 public function __construct(Product $product,MeasurementUnit $unit,string $quantity,CalculationMethod $method,int $sequence,?Material $material=null,?MaterialVariant $variant=null){if(($material===null)===($variant===null))throw new \InvalidArgumentException('La receta debe indicar material o variante, exclusivamente.');$this->product=$product;$this->unit=$unit;$this->quantity=$quantity;$this->calculationMethod=$method;$this->sequence=$sequence;$this->material=$material;$this->variant=$variant;$this->initializeTimestamps();}
}
