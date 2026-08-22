<?php
namespace App\Application\Clients;
use Symfony\Component\Validator\Constraints as Assert;
final class ClientInlineContactData {
 public ?int $id=null; #[Assert\NotBlank,Assert\Length(max:100)] public ?string $firstName=null; #[Assert\Length(max:120)] public ?string $lastName=null; #[Assert\Email,Assert\Length(max:180)] public ?string $personalEmail=null; #[Assert\NotBlank(message:'Captura el correo laboral del contacto.'),Assert\Email(message:'Captura un correo laboral válido.'),Assert\Length(max:180)] public ?string $businessEmail=null; public ?\DateTimeImmutable $birthDate=null; #[Assert\Length(max:120)] public ?string $department=null; #[Assert\Length(max:120)] public ?string $jobTitle=null; #[Assert\Length(max:100)] public ?string $workDays=null; #[Assert\Length(max:160)] public ?string $workHours=null; #[Assert\Length(max:2000)] public ?string $notes=null; public bool $isPrimary=false; public bool $canRequestProducts=true;
 /** @var list<ClientPhoneData> */ #[Assert\Valid] public array $phones=[];
}
