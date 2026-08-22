<?php
declare(strict_types=1);
namespace App\Application\Quotations;
use App\Entity\Catalog\{CommercialCategory,CommercialItem};
use Symfony\Component\Validator\Constraints as Assert;
final class PublicQuotationRequestItemData
{
 #[Assert\NotNull(message:'Selecciona una categoría.')] public ?CommercialCategory $commercialCategory=null;
 #[Assert\NotNull(message:'Selecciona un Producto.')] public ?CommercialItem $commercialItem=null;
 #[Assert\NotBlank(message:'Captura la cantidad.'),Assert\Regex(pattern:'/^(?:0|[1-9]\d{0,9})(?:[.,]\d{1,4})?$/',message:'La cantidad debe usar hasta cuatro decimales.')] public ?string $quantity='1.0000';
 /** @var array<string,string> */ public array $specifications=[];
 #[Assert\Choice(choices:[QuotationItemData::QUANTITY_MODE_AUTO,QuotationItemData::QUANTITY_MODE_MANUAL])] public string $quantityMode=QuotationItemData::QUANTITY_MODE_AUTO;
 #[Assert\Length(max:5000)] public ?string $notes=null;
 public ?string $attachmentPath=null; public ?string $attachmentOriginalName=null;
 public function toQuotationItemData():QuotationItemData{$item=new QuotationItemData();$item->commercialCategory=$this->commercialCategory;$item->commercialItem=$this->commercialItem;$item->quantity=$this->quantity;$item->specifications=$this->specifications;$item->quantityMode=$this->quantityMode;return $item;}
 public function requestDetails():array{return ['notes'=>$this->notes];}
}
