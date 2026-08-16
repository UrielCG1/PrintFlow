<?php
declare(strict_types=1);
namespace App\Entity\Products;
use App\Entity\Common\Timestampable; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity,ORM\Table(name:'product_categories'),ORM\HasLifecycleCallbacks]
class ProductCategory {
 use Timestampable;
 #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
 #[ORM\ManyToOne(targetEntity:self::class),ORM\JoinColumn(name:'parent_id',onDelete:'RESTRICT')] private ?self $parent=null;
 #[ORM\Column(length:40,unique:true)] private string $code;
 #[ORM\Column(length:120)] private string $name;
 #[ORM\Column(name:'is_active')] private bool $isActive=true;
 public function __construct(string $code,string $name){$this->code=strtoupper(trim($code));$this->name=trim($name);$this->initializeTimestamps();}
 public function getId():?int{return $this->id;} public function getName():string{return $this->name;} public function isActive():bool{return $this->isActive;}
}
