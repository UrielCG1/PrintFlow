<?php
declare(strict_types=1);
namespace App\Entity\Products;
use App\Entity\Catalog\MeasurementUnit; use App\Entity\Common\Timestampable; use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity,ORM\Table(name:'products'),ORM\HasLifecycleCallbacks]
/** Artículo o servicio vendido: fabricado, reventa, servicio o configuración personalizada. */
class Product
{
 use Timestampable; /** Identificador interno. */ #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
 /** Categoría comercial del producto. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'category_id',nullable:false,onDelete:'RESTRICT')] private ProductCategory $category;
 /** Unidad utilizada para cotizar y vender. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'sale_unit_id',nullable:false,onDelete:'RESTRICT')] private MeasurementUnit $saleUnit;
 /** Unidad base utilizada para planificar su fabricación. */ #[ORM\ManyToOne] #[ORM\JoinColumn(name:'production_unit_id',nullable:false,onDelete:'RESTRICT')] private MeasurementUnit $productionUnit;
 /** Código estable del producto. */ #[ORM\Column(length:80,unique:true)] private string $code; /** Nombre comercial visible. */ #[ORM\Column(length:180)] private string $name;
 /** Clasificación: MANUFACTURED, RESALE, SERVICE o CONFIGURABLE. */ #[ORM\Column(name:'product_type',length:20)] private string $productType; /** Explicación comercial opcional. */ #[ORM\Column(type:Types::TEXT,nullable:true)] private ?string $description=null;
 /** Precio base opcional expresado en MXN. */ #[ORM\Column(name:'base_price_mxn',type:Types::DECIMAL,precision:19,scale:4,nullable:true)] private ?string $basePriceMxn=null;
 /** Definición versionable de opciones, restricciones y valores predeterminados configurables. */ #[ORM\Column(name:'configuration_schema',type:Types::JSON,nullable:true)] private ?array $configurationSchema=null;
 /** Indica si genera ruta y orden de producción. */ #[ORM\Column(name:'requires_production')] private bool $requiresProduction=false; /** Indica si agrega actividades de instalación. */ #[ORM\Column(name:'requires_installation')] private bool $requiresInstallation=false;
 /** Indica si el producto terminado mantiene existencia propia. */ #[ORM\Column(name:'is_stock_item')] private bool $isStockItem=false; /** Permite usarlo en nuevas cotizaciones. */ #[ORM\Column(name:'is_active')] private bool $isActive=true;
 public function __construct(ProductCategory $category,string $code,string $name,MeasurementUnit $saleUnit,MeasurementUnit $productionUnit,string $type='CONFIGURABLE'){$this->category=$category;$this->code=strtoupper(trim($code));$this->name=trim($name);$this->saleUnit=$saleUnit;$this->productionUnit=$productionUnit;$this->productType=$type;$this->initializeTimestamps();}
 public function getId():?int{return $this->id;} public function getCategory():ProductCategory{return $this->category;} public function getSaleUnit():MeasurementUnit{return $this->saleUnit;} public function getCode():string{return $this->code;} public function getName():string{return $this->name;} public function getConfigurationSchema():?array{return $this->configurationSchema;} public function isActive():bool{return $this->isActive;}
}
