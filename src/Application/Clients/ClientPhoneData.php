<?php
namespace App\Application\Clients;
use Symfony\Component\Validator\Constraints as Assert;
final class ClientPhoneData {
 public ?int $id=null;
 #[Assert\Choice(choices:['LANDLINE','MOBILE','PERSONAL_MOBILE','FAX'])] public string $type='LANDLINE';
 #[Assert\NotBlank,Assert\Length(max:30)] public ?string $number=null;
 #[Assert\Length(max:5)] public string $countryCode='52'; #[Assert\Length(max:10)] public ?string $areaCode=null; #[Assert\Length(max:15)] public ?string $extension=null; #[Assert\Length(max:80)] public ?string $label=null; #[Assert\Length(max:500)] public ?string $notes=null; public bool $isPrimary=false;
}
