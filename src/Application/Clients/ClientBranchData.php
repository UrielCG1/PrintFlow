<?php
namespace App\Application\Clients;
use App\Entity\Clients\ClientCategory; use Symfony\Component\Validator\Constraints as Assert;
final class ClientBranchData {
 public ?int $id=null; #[Assert\NotBlank,Assert\Length(max:40)] public ?string $code=null; #[Assert\NotBlank,Assert\Length(max:160)] public ?string $name=null; #[Assert\Email,Assert\Length(max:180)] public ?string $email=null; #[Assert\Length(max:2000)] public ?string $notes=null; public ?ClientCategory $category=null; public bool $isMain=false;
 /** @var list<ClientBranchAddressData> */ #[Assert\Valid] public array $addresses=[];
 /** @var list<ClientPhoneData> */ #[Assert\Valid] public array $phones=[];
 /** @var list<ClientInlineContactData> */ #[Assert\Valid] public array $contacts=[];
}
