<?php
namespace App\Application\Suppliers;
use App\Application\Clients\{ClientBranchAddressData,ClientPhoneData}; use Symfony\Component\Validator\Constraints as Assert;
final class SupplierBranchData { public ?int $id=null; #[Assert\NotBlank,Assert\Length(max:40)] public ?string $code=null; #[Assert\NotBlank,Assert\Length(max:160)] public ?string $name=null; #[Assert\Email,Assert\Length(max:180)] public ?string $email=null; #[Assert\Length(max:2000)] public ?string $notes=null; public bool $isMain=false; /** @var list<ClientBranchAddressData> */ #[Assert\Valid] public array $addresses=[]; /** @var list<ClientPhoneData> */ #[Assert\Valid] public array $phones=[]; /** @var list<SupplierInlineContactData> */ #[Assert\Valid] public array $contacts=[]; }
