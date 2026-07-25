<?php

namespace App\Application\Clients;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ClientAddressData
{
    #[Assert\NotBlank(message: 'Captura una referencia para identificar la dirección.')]
    #[Assert\Length(max: 100)]
    public ?string $label = null;

    #[Assert\Length(max: 160)]
    public ?string $recipientName = null;

    #[Assert\NotBlank(message: 'Captura la calle.')]
    #[Assert\Length(max: 160)]
    public ?string $street = null;

    #[Assert\NotBlank(message: 'Captura el número exterior.')]
    #[Assert\Length(max: 30)]
    public ?string $exteriorNumber = null;

    #[Assert\Length(max: 30)]
    public ?string $interiorNumber = null;

    #[Assert\Length(max: 120)]
    public ?string $neighborhood = null;

    #[Assert\NotBlank(message: 'Captura el código postal.')]
    #[Assert\Regex(
        pattern: '/^\d{5}$/',
        message: 'El código postal debe contener exactamente 5 dígitos.'
    )]
    public ?string $postalCode = null;

    #[Assert\NotBlank(message: 'Captura el municipio o alcaldía.')]
    #[Assert\Length(max: 120)]
    public ?string $municipality = null;

    #[Assert\NotBlank(message: 'Captura el estado.')]
    #[Assert\Length(max: 120)]
    public ?string $state = null;

    #[Assert\Length(max: 1000)]
    public ?string $references = null;

    public bool $isFiscalAddress = false;

    public bool $isDeliveryAddress = false;

    public bool $isDefaultFiscal = false;

    public bool $isDefaultDelivery = false;

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (!$this->isFiscalAddress && !$this->isDeliveryAddress) {
            $context
                ->buildViolation('Selecciona al menos un uso para la dirección.')
                ->atPath('isFiscalAddress')
                ->addViolation();
        }

        if ($this->isDefaultFiscal && !$this->isFiscalAddress) {
            $context
                ->buildViolation('Solo una dirección fiscal puede ser fiscal predeterminada.')
                ->atPath('isDefaultFiscal')
                ->addViolation();
        }

        if ($this->isDefaultDelivery && !$this->isDeliveryAddress) {
            $context
                ->buildViolation('Solo una dirección de entrega puede ser predeterminada.')
                ->atPath('isDefaultDelivery')
                ->addViolation();
        }
    }
}