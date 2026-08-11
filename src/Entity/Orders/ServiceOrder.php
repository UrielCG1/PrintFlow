<?php

declare(strict_types=1);

namespace App\Entity\Orders;

use App\Entity\Quotations\Quotation;
use App\Entity\Users\User;
use App\Enum\Orders\ServiceOrderStatus;
use App\Repository\Orders\ServiceOrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServiceOrderRepository::class)]
#[ORM\Table(name: 'service_orders')]
#[ORM\UniqueConstraint(name: 'uniq_service_orders_folio', columns: ['folio'])]
#[ORM\UniqueConstraint(name: 'uniq_service_orders_source_quotation', columns: ['source_quotation_id'])]
#[ORM\Index(name: 'idx_service_orders_status_created_at', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_service_orders_created_by_user', columns: ['created_by_user_id'])]
#[ORM\HasLifecycleCallbacks]
class ServiceOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'source_quotation_id', nullable: false, onDelete: 'RESTRICT')]
    private Quotation $sourceQuotation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(length: 40)]
    private string $folio;

    #[ORM\Column(length: 30, enumType: ServiceOrderStatus::class)]
    private ServiceOrderStatus $status = ServiceOrderStatus::PENDING_PLANNING;

    #[ORM\Column(name: 'source_quotation_folio', length: 40)]
    private string $sourceQuotationFolio;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'quotation_snapshot', type: Types::JSON)]
    private array $quotationSnapshot = [];

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'client_snapshot', type: Types::JSON)]
    private array $clientSnapshot = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'fiscal_address_snapshot', type: Types::JSON, nullable: true)]
    private ?array $fiscalAddressSnapshot = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'delivery_address_snapshot', type: Types::JSON, nullable: true)]
    private ?array $deliveryAddressSnapshot = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'MXN';

    #[ORM\Column(name: 'discount_percent', type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $discountPercent = '0.00';

    #[ORM\Column(name: 'tax_rate', type: Types::DECIMAL, precision: 5, scale: 4)]
    private string $taxRate = '0.0000';

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

    #[ORM\Column(name: 'commitment_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $commitmentDate = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ServiceOrderItem> */
    #[ORM\OneToMany(
        mappedBy: 'serviceOrder',
        targetEntity: ServiceOrderItem::class,
        cascade: ['persist'],
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

    public function getSourceQuotation(): Quotation
    {
        return $this->sourceQuotation;
    }

    public function setSourceQuotation(Quotation $sourceQuotation): self
    {
        $this->sourceQuotation = $sourceQuotation;

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

    public function getFolio(): string
    {
        return $this->folio;
    }

    public function setFolio(string $folio): self
    {
        $folio = strtoupper(trim($folio));

        if (preg_match('/^[A-Z0-9-]{1,40}$/D', $folio) !== 1) {
            throw new \InvalidArgumentException('El folio de la orden no tiene un formato válido.');
        }

        $this->folio = $folio;

        return $this;
    }

    public function getStatus(): ServiceOrderStatus
    {
        return $this->status;
    }

    public function getSourceQuotationFolio(): string
    {
        return $this->sourceQuotationFolio;
    }

    public function setSourceQuotationFolio(string $sourceQuotationFolio): self
    {
        $sourceQuotationFolio = strtoupper(trim($sourceQuotationFolio));

        if (preg_match('/^[A-Z0-9-]{1,40}$/D', $sourceQuotationFolio) !== 1) {
            throw new \InvalidArgumentException('El folio de origen de la orden no tiene un formato válido.');
        }

        $this->sourceQuotationFolio = $sourceQuotationFolio;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getQuotationSnapshot(): array
    {
        return $this->quotationSnapshot;
    }

    /** @param array<string, mixed> $quotationSnapshot */
    public function setQuotationSnapshot(array $quotationSnapshot): self
    {
        $this->quotationSnapshot = $quotationSnapshot;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getClientSnapshot(): array
    {
        return $this->clientSnapshot;
    }

    /** @param array<string, mixed> $clientSnapshot */
    public function setClientSnapshot(array $clientSnapshot): self
    {
        $this->clientSnapshot = $clientSnapshot;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getFiscalAddressSnapshot(): ?array
    {
        return $this->fiscalAddressSnapshot;
    }

    /** @param array<string, mixed>|null $fiscalAddressSnapshot */
    public function setFiscalAddressSnapshot(?array $fiscalAddressSnapshot): self
    {
        $this->fiscalAddressSnapshot = $fiscalAddressSnapshot;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getDeliveryAddressSnapshot(): ?array
    {
        return $this->deliveryAddressSnapshot;
    }

    /** @param array<string, mixed>|null $deliveryAddressSnapshot */
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

    public function getDiscountPercent(): string
    {
        return $this->discountPercent;
    }

    public function setDiscountPercent(string $discountPercent): self
    {
        $value = trim(str_replace(',', '.', $discountPercent));

        if (preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,2})?$/D', $value) !== 1 || (float) $value > 100) {
            throw new \InvalidArgumentException('El descuento de la orden no tiene un formato válido.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
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

        if (preg_match('/^(?:0|1)(?:\.\d{1,4})?$/D', $value) !== 1 || ((float) $value) > 1) {
            throw new \InvalidArgumentException('La tasa de impuesto de la orden no tiene un formato válido.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
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
        $this->subtotal = Quotation::normalizeAmount($subtotal, 'El subtotal de la orden');
        $this->discountAmount = Quotation::normalizeAmount($discountAmount, 'El descuento de la orden');
        $this->taxableAmount = Quotation::normalizeAmount($taxableAmount, 'La base gravable de la orden');
        $this->taxAmount = Quotation::normalizeAmount($taxAmount, 'El impuesto de la orden');
        $this->total = Quotation::normalizeAmount($total, 'El total de la orden');

        return $this;
    }

    public function getCommitmentDate(): ?\DateTimeImmutable
    {
        return $this->commitmentDate;
    }

    /**
     * La fecha se conserva nula al crear la orden porque el compromiso se
     * definirá durante la planificación, no durante la aceptación comercial.
     */
    public function setCommitmentDate(?\DateTimeImmutable $commitmentDate): self
    {
        $this->commitmentDate = $commitmentDate;

        return $this;
    }

    /** @return Collection<int, ServiceOrderItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(ServiceOrderItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setServiceOrder($this);
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
