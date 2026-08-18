<?php
namespace App\Application\Clients;
use App\Entity\Clients\DeliveryZone;
use Symfony\Component\Validator\Constraints as Assert;
final class ClientBranchAddressData {
 public ?int $id=null; #[Assert\Choice(choices:['FISCAL','COMMERCIAL','DELIVERY'])] public string $type='COMMERCIAL'; public ?DeliveryZone $deliveryZone=null; #[Assert\PositiveOrZero] public ?float $deliveryCost=null;
 #[Assert\NotBlank,Assert\Length(max:160)] public ?string $street=null; #[Assert\NotBlank,Assert\Length(max:30)] public ?string $exteriorNumber=null; #[Assert\Length(max:30)] public ?string $interiorNumber=null; #[Assert\Length(max:120)] public ?string $neighborhood=null; #[Assert\NotBlank,Assert\Length(max:10)] public ?string $postalCode=null; #[Assert\NotBlank,Assert\Length(max:120)] public ?string $city=null; #[Assert\Length(max:120)] public ?string $state=null; #[Assert\Country] public string $countryCode='MX'; #[Assert\Length(max:1000)] public ?string $notes=null; public bool $isDefault=false;
}
