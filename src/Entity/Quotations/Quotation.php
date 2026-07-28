<?php

namespace App\Entity\Quotations;

use App\Entity\Clients\Client;
use App\Entity\Users\User;
use App\Enum\Quotations\QuotationStatus;
use App\Repository\Quotations\QuotationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuotationRepository::class)]
#[ORM\Table(name: 'quotations')]
#[ORM\UniqueConstraint(name: 'uniq_quotations_folio', columns: ['folio'])]
#[ORM\Index(name: 'idx_quotations_status_expires_at', columns: ['status', 'expires_at'])]
#[ORM\Index(name: 'idx_quotations_client_created_at', columns: ['client_id', 'created_at'])]
#[ORM\Index(name: 'idx_quotations_created_by_user', columns: ['created_by_user_id'])]
#[ORM\HasLifecycleCallbacks]
class Quotation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'client_id', nullable: false, onDelete: 'RESTRICT')]
    private Client $client;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(length: 20, enumType: QuotationStatus::class)]
    private QuotationStatus $status = QuotationStatus::DRAFT;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $folio = null;

    #[ORM\Column(name: 'expires_at', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'issued_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column(length: 3, options: ['default' => 'MXN'])]
    private string $currency = 'MXN';

    #[ORM\Column(name: 'client_snapshot', type: Types::JSON)]
    private array $clientSnapshot = [];

    #[ORM\Column(name: 'fiscal_address_snapshot', type: Types::JSON, nullable: true)]
    private ?array $fiscalAddressSnapshot = null;

    #[ORM\Column(name: 'delivery_address_snapshot', type: Types::JSON, nullable: true)]
    private ?array $deliveryAddressSnapshot = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'discount_percent', type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $discountPercent = '0.00';

    #[ORM\Column(name: 'tax_rate', type: Types::DECIMAL, precision: 5, scale: 4)]
    private string $taxRate = '0.1600';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $subtotal = '0.00';

    #[ORM\Column(name: 'discount_amount', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $discountAmount = '0.00';

    #[ORM\Column(name: 'taxable_amount', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $taxableAmount = '0.00';

    #[ORM\Column(name: 'tax_amount', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $taxAmount = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $total = '0.00';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, QuotationItem>
     */
    #[ORM\OneToMany(
        mappedBy: 'quotation',
        targetEntity: QuotationItem::class,
        cascade: ['persist'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['lineNumber' => 'ASC'])]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();

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

    public function setClient(Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getStatus(): QuotationStatus
    {
        return $this->status;
    }
        
    public function getFolio(): ?string
    {
        return $this->folio;
    }

    public function hasBeenIssued(): bool
    {
        return $this->folio !== null && $this->issuedAt !== null;
    }

    public function issue(string $folio, \DateTimeImmutable $issuedAt): void
    {
        if (!$this->isEditable()) {
            throw new \DomainException(
                'Solo una cotización en borrador puede emitirse.',
            );
        }

        $this->folio = self::normalizeIssuedFolio($folio);
        $this->issuedAt = $issuedAt->setTimezone(
            new \DateTimeZone('UTC'),
        );
        $this->status = QuotationStatus::ISSUED;
    }

    private static function normalizeIssuedFolio(string $folio): string
    {
        $folio = strtoupper(trim($folio));

        if (preg_match('/^[A-Z0-9-]{1,40}$/D', $folio) !== 1) {
            throw new \InvalidArgumentException(
                'El folio no tiene un formato válido.',
            );
        }

        return $folio;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getIssuedAt(): ?\DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(?\DateTimeImmutable $issuedAt): self
    {
        $this->issuedAt = $issuedAt;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new \InvalidArgumentException('La moneda debe usar un código ISO de tres letras.');
        }

        $this->currency = $currency;

        return $this;
    }

    public function getClientSnapshot(): array
    {
        return $this->clientSnapshot;
    }

    public function setClientSnapshot(array $clientSnapshot): self
    {
        $this->clientSnapshot = $clientSnapshot;

        return $this;
    }

    public function getFiscalAddressSnapshot(): ?array
    {
        return $this->fiscalAddressSnapshot;
    }

    public function setFiscalAddressSnapshot(?array $fiscalAddressSnapshot): self
    {
        $this->fiscalAddressSnapshot = $fiscalAddressSnapshot;

        return $this;
    }

    public function getDeliveryAddressSnapshot(): ?array
    {
        return $this->deliveryAddressSnapshot;
    }

    public function setDeliveryAddressSnapshot(?array $deliveryAddressSnapshot): self
    {
        $this->deliveryAddressSnapshot = $deliveryAddressSnapshot;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $notes = trim((string) $notes);
        $this->notes = $notes !== '' ? $notes : null;

        return $this;
    }

    public function getDiscountPercent(): string
    {
        return $this->discountPercent;
    }

    public function setDiscountPercent(string $discountPercent): self
    {
        $value = trim(str_replace(',', '.', $discountPercent));

        if (preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,2})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('El descuento no tiene un formato válido.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        if ((int) $integer > 100) {
            throw new \InvalidArgumentException('El descuento no puede ser mayor a 100 %.');
        }

        $this->discountPercent = $integer.'.'.str_pad($decimal, 2, '0');

        return $this;
    }

    public function getTaxRate(): string
    {
        return $this->taxRate;
    }

    public function setTaxRate(string $taxRate): self
    {
        $value = trim(str_replace(',', '.', $taxRate));

        if (preg_match('/^(?:0|1)(?:\.\d{1,4})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('La tasa de impuesto no tiene un formato válido.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        if ($integer === '1' && trim($decimal, '0') !== '') {
            throw new \InvalidArgumentException('La tasa de impuesto no puede ser mayor a 1.');
        }

        $this->taxRate = $integer.'.'.str_pad($decimal, 4, '0');

        return $this;
    }

    public function getSubtotal(): string
    {
        return $this->subtotal;
    }

    public function getDiscountAmount(): string
    {
        return $this->discountAmount;
    }

    public function getTaxableAmount(): string
    {
        return $this->taxableAmount;
    }

    public function getTaxAmount(): string
    {
        return $this->taxAmount;
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function setTotals(
        string $subtotal,
        string $discountAmount,
        string $taxableAmount,
        string $taxAmount,
        string $total,
    ): self {
        $this->subtotal = self::normalizeAmount($subtotal, 'El subtotal');
        $this->discountAmount = self::normalizeAmount($discountAmount, 'El descuento');
        $this->taxableAmount = self::normalizeAmount($taxableAmount, 'La base gravable');
        $this->taxAmount = self::normalizeAmount($taxAmount, 'El impuesto');
        $this->total = self::normalizeAmount($total, 'El total');

        return $this;
    }

    /**
     * @return Collection<int, QuotationItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(QuotationItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setQuotation($this);
        }

        return $this;
    }

    public function removeItem(QuotationItem $item): self
    {
        $this->items->removeElement($item);

        return $this;
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public static function normalizeAmount(string $amount, string $field): string
    {
        $value = trim(str_replace(',', '.', $amount));

        if (preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field.' no tiene un formato válido.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';

        return $integer.'.'.str_pad($decimal, 2, '0');
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}