<?php
declare(strict_types=1);
namespace App\Application\Quotations;
use App\Entity\Catalog\{CommercialCategory,CommercialItem,MeasurementUnit};
use Symfony\Component\Validator\Constraints as Assert;
final class PublicQuotationRequestItemData
{
 #[Assert\NotNull] public ?CommercialCategory $category=null;
 #[Assert\NotNull] public ?CommercialItem $product=null;
 #[Assert\NotBlank] public string $quantity='1';
 public ?string $width=null; public ?string $height=null; public ?MeasurementUnit $measurementUnit=null; public ?string $material=null; public ?string $printSides=null; public array $finishes=[]; public array $characteristics=[];
 #[Assert\Length(max:5000)] public ?string $notes=null;
 public ?string $attachmentPath=null; public ?string $attachmentOriginalName=null;
 public function toQuotationItemData():QuotationItemData{$item=new QuotationItemData();$item->commercialCategory=$this->category;$item->commercialItem=$this->product;$item->quantity=$this->quantity;$item->quantityMode='MANUAL';$item->specifications=$this->characteristics;if(trim((string)$this->width)!=='')$item->specifications['finished_width_cm']=$this->width;if(trim((string)$this->height)!=='')$item->specifications['finished_height_cm']=$this->height;if($this->product?->getQuotationSpecificationProfile()->value==='LARGE_FORMAT')$item->quantityMode='AUTO';return $item;}
 public function requestDetails():array{return ['width'=>$this->width,'height'=>$this->height,'measurement_unit'=>$this->measurementUnit?->getCode(),'material'=>$this->material,'print_sides'=>$this->printSides,'finishes'=>$this->finishes,'notes'=>$this->notes];}
}
