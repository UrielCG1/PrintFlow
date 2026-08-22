<?php

namespace App\Application\Clients;

use App\Entity\Clients\ClientCategory;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ClientData
{
    /** @var list<ClientPhoneData> */ #[Assert\Valid] public array $phones = [];
    /** @var list<ClientBranchData> */ #[Assert\Valid] public array $branches = [];
    #[Assert\Choice(choices: ['COMPANY', 'INDIVIDUAL'])]
    public string $clientType = 'COMPANY';

    #[Assert\NotBlank(message: 'Captura el nombre o razón social.')]
    #[Assert\Length(max: 160)]
    public ?string $businessName = null;

    #[Assert\Length(max: 20)]
    public ?string $taxId = null;

    #[Assert\Length(max: 160)]
    public ?string $legalName = null;

    #[Assert\Length(max: 160)] public ?string $businessActivity = null;
    #[Assert\Url(requireTld: true, message: 'Captura una URL válida.')] #[Assert\Length(max: 255)] public ?string $website = null;
    public ?\DateTimeImmutable $birthDate = null;

    #[Assert\Regex(
        pattern: '/^\d{3}$/',
        message: 'El régimen fiscal debe contener exactamente 3 dígitos.'
    )]
    public ?string $taxRegimeCode = null;

    #[Assert\Email(message: 'Captura un correo de facturación válido.')]
    #[Assert\Length(max: 180)]
    public ?string $billingEmail = null;

    #[Assert\Length(max: 10)]
    #[Assert\Regex(
        pattern: '/^[A-Za-z][A-Za-z0-9]*$/',
        message: 'El uso CFDI solo puede contener letras y números.'
    )]
    public ?string $defaultCfdiUseCode = null;

    public ?ClientCategory $category = null;

    #[Assert\Email(message: 'Captura un correo electrónico válido.')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Length(max: 40)]
    #[Assert\Regex(
        pattern: '/^[0-9+\-() ]*$/',
        message: 'El teléfono contiene caracteres no permitidos.'
    )]
    public ?string $phone = null;

    #[Assert\Length(max: 2000)]
    public ?string $notes = null;

    #[Assert\Callback]
    public function validateCollections(ExecutionContextInterface $context): void
    {
        if ($this->clientType === 'INDIVIDUAL') {
            if (trim((string) $this->email) === '') { $context->buildViolation('Captura el correo del titular.')->atPath('email')->addViolation(); }
            if ($this->branches !== []) { $context->buildViolation('Una persona física no puede tener sucursales. Registra sus domicilios y contactos directamente después de crearla.')->atPath('branches')->addViolation(); }
        }
        $mainBranches=0;$primaryContacts=0;$codes=[];
        foreach($this->branches as $branch){if($branch->isMain)$mainBranches++;$code=strtoupper(trim((string)$branch->code));if($code!==''&&isset($codes[$code])){$context->buildViolation('El código de cada sucursal debe ser único dentro del cliente.')->atPath('branches')->addViolation();} $codes[$code]=true;foreach($branch->contacts as $contact){if($contact->isPrimary)$primaryContacts++;}}
        if($mainBranches>1){$context->buildViolation('Solo puede existir una sucursal principal.')->atPath('branches')->addViolation();}
        if($primaryContacts>1){$context->buildViolation('Solo puede existir un contacto principal por cliente.')->atPath('branches')->addViolation();}
    }
}
