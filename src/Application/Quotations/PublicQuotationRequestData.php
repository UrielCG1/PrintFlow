<?php
declare(strict_types=1);
namespace App\Application\Quotations;
use Symfony\Component\Validator\Constraints as Assert;
final class PublicQuotationRequestData
{
 public ?string $customerNumber=null;
 #[Assert\NotBlank(message:'Captura el nombre completo.',groups:['public_prospect']),Assert\Length(max:150)] public ?string $fullName=null;
 #[Assert\NotBlank(message:'Captura el correo electrónico.',groups:['public_prospect']),Assert\Email(message:'Captura un correo electrónico válido.'),Assert\Length(max:180)] public ?string $email=null;
 #[Assert\NotBlank(message:'Captura el teléfono o WhatsApp.',groups:['public_prospect']),Assert\Length(max:30)] public ?string $phone=null;
 #[Assert\Length(max:180)] public ?string $companyName=null;
 #[Assert\Choice(['whatsapp','email','phone'])] public string $contactPreference='whatsapp';
 #[Assert\Valid,Assert\Count(min:1,max:100)] public array $items=[];
 public ?\DateTimeImmutable $neededAt=null;
 #[Assert\Choice(['pickup','shipping','undefined'])] public string $deliveryMethod='pickup';
 public bool $requiresInvoice=false;
 public function addItem(PublicQuotationRequestItemData $item):void{$this->items[]=$item;}
 public function removeItem(PublicQuotationRequestItemData $item):void{$this->items=array_values(array_filter($this->items,static fn($current)=>$current!==$item));}
}
