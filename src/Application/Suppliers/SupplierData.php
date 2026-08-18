<?php

namespace App\Application\Suppliers;

use App\Entity\Suppliers\Supplier;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use App\Application\Clients\ClientPhoneData;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[UniqueEntity(
    fields: ['code'],
    entityClass: Supplier::class,
    identifierFieldNames: ['id'],
    errorPath: 'code',
    message: 'Ya existe un proveedor con este código.',
)]
#[UniqueEntity(
    fields: ['taxId'],
    entityClass: Supplier::class,
    identifierFieldNames: ['id'],
    errorPath: 'taxId',
    ignoreNull: true,
    message: 'Ya existe un proveedor con este RFC.',
)]
final class SupplierData
{
    /** @var list<ClientPhoneData> */ #[Assert\Valid] public array $phones=[];
    /** @var list<SupplierBranchData> */ #[Assert\Valid] public array $branches=[];
    public ?int $id = null;

    #[Assert\NotBlank(message: 'El código es obligatorio.')]
    #[Assert\Length(max: 80)]
    #[Assert\Regex(
        pattern: '/^[A-Z0-9][A-Z0-9._-]*$/i',
        message: 'Usa letras, números, puntos, guiones o guiones bajos.',
    )]
    public ?string $code = null;

    #[Assert\NotBlank(message: 'Captura el nombre comercial.')]
    #[Assert\Length(max: 160)]
    public ?string $businessName = null;

    #[Assert\Length(max: 160)]
    public ?string $legalName = null;

    #[Assert\Length(max: 20)]
    public ?string $taxId = null;
    #[Assert\Regex(pattern:'/^[0-9]{3}$/',message:'El régimen fiscal debe contener 3 dígitos.')] public ?string $taxRegimeCode=null;
    #[Assert\Email,Assert\Length(max:180)] public ?string $billingEmail=null;
    #[Assert\Length(max:10)] public ?string $defaultCfdiUseCode=null;

    #[Assert\Length(max: 160)] public ?string $businessActivity = null;
    #[Assert\Url(requireTld: true, message: 'Captura una URL válida.')] #[Assert\Length(max: 255)] public ?string $website = null;

    #[Assert\Email(message: 'Captura un correo electrónico válido.')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Length(max: 40)]
    #[Assert\Regex(
        pattern: '/^[0-9+\-() ]*$/',
        message: 'El teléfono contiene caracteres no permitidos.',
    )]
    public ?string $phone = null;

    #[Assert\Length(max: 2000)]
    public ?string $notes = null;
    #[Assert\Callback] public function validateCollections(ExecutionContextInterface $context):void{$main=0;$primary=0;$codes=[];foreach($this->branches as $branch){if($branch->isMain)$main++;$code=strtoupper(trim((string)$branch->code));if($code!==''&&isset($codes[$code]))$context->buildViolation('El código de cada sucursal debe ser único.')->atPath('branches')->addViolation();$codes[$code]=true;foreach($branch->contacts as $contact){if($contact->isPrimary)$primary++;}}if($main>1)$context->buildViolation('Solo puede existir una sucursal principal.')->atPath('branches')->addViolation();if($primary>1)$context->buildViolation('Solo puede existir un contacto principal por proveedor.')->atPath('branches')->addViolation();}
}
