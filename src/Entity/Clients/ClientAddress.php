<?php

namespace App\Entity\Clients;

use App\Repository\Clients\ClientAddressRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClientAddressRepository::class)]
#[ORM\Table(name: 'client_addresses')]
#[ORM\Index(name: 'idx_client_addresses_client_active', columns: ['client_id', 'is_active'])]
#[ORM\Index(name: 'idx_client_addresses_delivery_zone', columns: ['delivery_zone_id'])]
#[ORM\UniqueConstraint(name: 'uniq_client_addresses_default_fiscal', columns: ['default_fiscal_client_id'])]
#[ORM\UniqueConstraint(name: 'uniq_client_addresses_default_delivery', columns: ['default_delivery_client_id'])]
#[ORM\HasLifecycleCallbacks]
class ClientAddress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', nullable: false, onDelete: 'RESTRICT')]
    private Client $client;

    #[ORM\Column(length: 100)]
    private string $label;

    #[ORM\Column(name: 'recipient_name', length: 160, nullable: true)]
    private ?string $recipientName = null;

    #[ORM\Column(length: 160)]
    private string $street;

    #[ORM\Column(name: 'exterior_number', length: 30)]
    private string $exteriorNumber;

    #[ORM\Column(name: 'interior_number', length: 30, nullable: true)]
    private ?string $interiorNumber = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $neighborhood = null;

    #[ORM\Column(
        name: 'postal_code',
        length: 5,
        options: ['fixed' => true]
    )]
    private string $postalCode;

    #[ORM\Column(length: 120)]
    private string $municipality;

    #[ORM\Column(length: 120)]
    private string $state;

    #[ORM\Column(
        name: 'country_code',
        length: 2,
        options: ['fixed' => true, 'default' => 'MX']
    )]
    private string $countryCode = 'MX';

    #[ORM\Column(
        name: 'references_text',
        type: 'text',
        length: 65535,
        nullable: true,
        options: ['default' => null]
    )]
    private ?string $references = null;

    #[ORM\ManyToOne(targetEntity: DeliveryZone::class)]
    #[ORM\JoinColumn(name: 'delivery_zone_id', nullable: true, onDelete: 'RESTRICT')]
    private ?DeliveryZone $deliveryZone = null;

    #[ORM\Column(name: 'delivery_cost', type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $deliveryCost = '0.00';

    #[ORM\Column(name: 'is_fiscal_address', options: ['default' => false])]
    private bool $isFiscalAddress = false;

    #[ORM\Column(name: 'is_delivery_address', options: ['default' => false])]
    private bool $isDeliveryAddress = false;

    #[ORM\Column(name: 'is_default_fiscal', options: ['default' => false])]
    private bool $isDefaultFiscal = false;

    #[ORM\Column(name: 'is_default_delivery', options: ['default' => false])]
    private bool $isDefaultDelivery = false;

    #[ORM\Column(
        name: 'default_fiscal_client_id',
        type: 'integer',
        nullable: true,
        insertable: false,
        updatable: false,
        generated: 'ALWAYS',
        columnDefinition: 'INT GENERATED ALWAYS AS (CASE WHEN is_active = 1 AND is_fiscal_address = 1 AND is_default_fiscal = 1 THEN client_id ELSE NULL END) STORED',
    )]
    private ?int $defaultFiscalClientId = null;

    #[ORM\Column(
        name: 'default_delivery_client_id',
        type: 'integer',
        nullable: true,
        insertable: false,
        updatable: false,
        generated: 'ALWAYS',
        columnDefinition: 'INT GENERATED ALWAYS AS (CASE WHEN is_active = 1 AND is_delivery_address = 1 AND is_default_delivery = 1 THEN client_id ELSE NULL END) STORED',
    )]
    private ?int $defaultDeliveryClientId = null;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(
        name: 'created_at',
        type: 'datetime_immutable',
        options: ['comment' => '(DC2Type:datetime_immutable)']
    )]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(
        name: 'updated_at',
        type: 'datetime_immutable',
        nullable: false,
        options: ['comment' => '(DC2Type:datetime_immutable)']
    )]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Client $client)
    {
        $this->client = $client;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = trim($label);

        return $this;
    }

    public function getRecipientName(): ?string
    {
        return $this->recipientName;
    }

    public function setRecipientName(?string $recipientName): self
    {
        $recipientName = trim((string) $recipientName);
        $this->recipientName = $recipientName !== '' ? $recipientName : null;

        return $this;
    }

    public function getStreet(): string
    {
        return $this->street;
    }

    public function setStreet(string $street): self
    {
        $this->street = trim($street);

        return $this;
    }

    public function getExteriorNumber(): string
    {
        return $this->exteriorNumber;
    }

    public function setExteriorNumber(string $exteriorNumber): self
    {
        $this->exteriorNumber = trim($exteriorNumber);

        return $this;
    }

    public function getInteriorNumber(): ?string
    {
        return $this->interiorNumber;
    }

    public function setInteriorNumber(?string $interiorNumber): self
    {
        $interiorNumber = trim((string) $interiorNumber);
        $this->interiorNumber = $interiorNumber !== '' ? $interiorNumber : null;

        return $this;
    }

    public function getNeighborhood(): ?string
    {
        return $this->neighborhood;
    }

    public function setNeighborhood(?string $neighborhood): self
    {
        $neighborhood = trim((string) $neighborhood);
        $this->neighborhood = $neighborhood !== '' ? $neighborhood : null;

        return $this;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function setPostalCode(string $postalCode): self
    {
        $this->postalCode = trim($postalCode);

        return $this;
    }

    public function getMunicipality(): string
    {
        return $this->municipality;
    }

    public function setMunicipality(string $municipality): self
    {
        $this->municipality = trim($municipality);

        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): self
    {
        $this->state = trim($state);

        return $this;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function setReferences(?string $references): self
    {
        $references = trim((string) $references);
        $this->references = $references !== '' ? $references : null;

        return $this;
    }

    public function getReferences(): ?string
    {
        return $this->references;
    }

    public function getDeliveryZone(): ?DeliveryZone
    {
        return $this->deliveryZone;
    }

    public function setDeliveryZone(?DeliveryZone $deliveryZone): self
    {
        $this->deliveryZone = $deliveryZone;

        return $this;
    }

    public function getDeliveryCost(): string
    {
        return $this->deliveryCost;
    }

    public function setDeliveryCost(string $deliveryCost): self
    {
        $this->deliveryCost = number_format(
            (float) str_replace(',', '.', trim($deliveryCost)),
            2,
            '.',
            ''
        );

        return $this;
    }

    public function isFiscalAddress(): bool
    {
        return $this->isFiscalAddress;
    }

    public function setIsFiscalAddress(bool $isFiscalAddress): self
    {
        $this->isFiscalAddress = $isFiscalAddress;

        return $this;
    }

    public function isDeliveryAddress(): bool
    {
        return $this->isDeliveryAddress;
    }

    public function setIsDeliveryAddress(bool $isDeliveryAddress): self
    {
        $this->isDeliveryAddress = $isDeliveryAddress;

        return $this;
    }

    public function isDefaultFiscal(): bool
    {
        return $this->isDefaultFiscal;
    }

    public function setIsDefaultFiscal(bool $isDefaultFiscal): self
    {
        $this->isDefaultFiscal = $isDefaultFiscal;

        return $this;
    }

    public function isDefaultDelivery(): bool
    {
        return $this->isDefaultDelivery;
    }

    public function setIsDefaultDelivery(bool $isDefaultDelivery): self
    {
        $this->isDefaultDelivery = $isDefaultDelivery;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        if (!$isActive) {
            $this->isDefaultFiscal = false;
            $this->isDefaultDelivery = false;
        }

        return $this;
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}