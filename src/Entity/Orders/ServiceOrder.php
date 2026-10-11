<?php

declare(strict_types=1);

namespace App\Entity\Orders;

use App\Entity\Quotations\Quotation;
use App\Entity\Users\User;
use App\Enum\Orders\ProductionOrderStatus;
use App\Enum\Orders\ServiceOrderDeliveryStatus;
use App\Enum\Orders\ServiceOrderFulfillmentMethod;
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
#[ORM\Index(name: 'idx_service_orders_production_status_created_at', columns: ['production_status', 'created_at'])]
#[ORM\Index(name: 'idx_service_orders_delivery_status_created_at', columns: ['delivery_status', 'created_at'])]
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

    #[ORM\Column(name: 'production_status', length: 30, enumType: ProductionOrderStatus::class, options: ['default' => 'WAITING_DESIGN'])]
    private ProductionOrderStatus $productionStatus = ProductionOrderStatus::WAITING_DESIGN;

    #[ORM\Column(name: 'delivery_status', length: 30, enumType: ServiceOrderDeliveryStatus::class, options: ['default' => 'NOT_READY'])]
    private ServiceOrderDeliveryStatus $deliveryStatus = ServiceOrderDeliveryStatus::NOT_READY;

    #[ORM\Column(name: 'fulfillment_method', length: 20, enumType: ServiceOrderFulfillmentMethod::class, options: ['default' => 'undefined'])]
    private ServiceOrderFulfillmentMethod $fulfillmentMethod = ServiceOrderFulfillmentMethod::UNDEFINED;

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

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'delivery_contact_snapshot', type: Types::JSON)]
    private array $deliveryContactSnapshot = [];

    #[ORM\Column(name: 'delivery_preparation_issue', length: 255, nullable: true)]
    private ?string $deliveryPreparationIssue = 'La modalidad de entrega sigue pendiente de definición por Ventas.';

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

    #[ORM\Column(name: 'commitment_reason', length: 500, nullable: true)]
    private ?string $commitmentReason = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'planned_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $plannedBy = null;

    #[ORM\Column(name: 'planned_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $plannedAt = null;

    #[ORM\Version]
    #[ORM\Column(name: 'traceability_version', type: Types::INTEGER, options: ['unsigned' => true, 'default' => 1])]
    private int $traceabilityVersion = 1;

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

    public function getProductionStatus(): ProductionOrderStatus
    {
        return $this->productionStatus;
    }

    public function getDeliveryStatus(): ServiceOrderDeliveryStatus
    {
        return $this->deliveryStatus;
    }

    public function getFulfillmentMethod(): ServiceOrderFulfillmentMethod
    {
        return $this->fulfillmentMethod;
    }

    public function setFulfillmentMethod(ServiceOrderFulfillmentMethod $fulfillmentMethod): self
    {
        $this->fulfillmentMethod = $fulfillmentMethod;

        return $this;
    }

    public function getTraceabilityVersion(): int
    {
        return $this->traceabilityVersion;
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

    /** @return array<string, mixed> */
    public function getDeliveryContactSnapshot(): array
    {
        return $this->deliveryContactSnapshot;
    }

    /** @param array<string, mixed> $deliveryContactSnapshot */
    public function setDeliveryContactSnapshot(array $deliveryContactSnapshot): self
    {
        $this->deliveryContactSnapshot = $deliveryContactSnapshot;

        return $this;
    }

    public function getDeliveryPreparationIssue(): ?string
    {
        return $this->deliveryPreparationIssue;
    }

    public function setDeliveryPreparationIssue(?string $deliveryPreparationIssue): self
    {
        $deliveryPreparationIssue = trim((string) $deliveryPreparationIssue);
        $this->deliveryPreparationIssue = $deliveryPreparationIssue !== ''
            ? mb_substr($deliveryPreparationIssue, 0, 255)
            : null;

        return $this;
    }

    public function isDeliveryPrepared(): bool
    {
        return $this->deliveryPreparationIssue === null;
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

    public function getCommitmentReason(): ?string
    {
        return $this->commitmentReason;
    }

    public function setCommitmentReason(?string $reason): self
    {
        $reason = trim((string) $reason);
        $this->commitmentReason = $reason !== '' ? mb_substr($reason, 0, 500) : null;

        return $this;
    }

    public function isPendingPlanning(): bool
    {
        return $this->status === ServiceOrderStatus::PENDING_PLANNING;
    }

    public function isPlanned(): bool
    {
        return $this->status === ServiceOrderStatus::PLANNED;
    }

    public function getPlannedBy(): ?User
    {
        return $this->plannedBy;
    }

    public function getPlannedAt(): ?\DateTimeImmutable
    {
        return $this->plannedAt;
    }

    /**
     * El estado de planificación solo se confirma después de que el manager
     * valide fecha compromiso y la ruta de todas las partidas.
     */
    public function markPlanned(User $actor): self
    {
        if (!$this->isPendingPlanning()) {
            throw new \DomainException('La orden ya fue planificada.');
        }

        if ($this->commitmentDate === null) {
            throw new \DomainException('Asigna la fecha compromiso antes de marcar la orden como planificada.');
        }

        $this->status = ServiceOrderStatus::PLANNED;
        $this->plannedBy = $actor;
        $this->plannedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }

    /**
     * La producción se inicia solamente después de planificar la orden. La
     * primera operación de la ruta será Diseño, incluso cuando solo valide el
     * archivo original del cliente.
     */
    public function beginProduction(): self
    {
        if (!$this->isPlanned()) {
            throw new \DomainException('La orden debe estar planificada antes de iniciar Producción.');
        }
        if ($this->productionStatus !== ProductionOrderStatus::WAITING_DESIGN) {
            throw new \DomainException('La orden no está pendiente de Diseño.');
        }

        $this->productionStatus = ProductionOrderStatus::IN_PRODUCTION;

        return $this;
    }

    /** Se habilita Calidad cuando ya no quedan operaciones productivas pendientes. */
    public function waitForQualityReview(): self
    {
        if ($this->productionStatus !== ProductionOrderStatus::IN_PRODUCTION) {
            throw new \DomainException('Solo una orden en producción puede enviarse a Calidad.');
        }

        $this->productionStatus = ProductionOrderStatus::WAITING_QUALITY;

        return $this;
    }

    /** La no conformidad de Calidad conserva la orden dentro del flujo productivo. */
    public function returnForQualityRework(): self
    {
        if ($this->productionStatus !== ProductionOrderStatus::WAITING_QUALITY) {
            throw new \DomainException('Solo una orden pendiente de Calidad puede devolverse a retrabajo.');
        }

        $this->productionStatus = ProductionOrderStatus::QUALITY_REWORK;

        return $this;
    }

    /** Lo invoca el flujo de retrabajo autorizado antes de crear el nuevo intento. */
    public function resumeProductionAfterQualityRework(): self
    {
        if ($this->productionStatus !== ProductionOrderStatus::QUALITY_REWORK) {
            throw new \DomainException('La orden no se encuentra en retrabajo de Calidad.');
        }

        $this->productionStatus = ProductionOrderStatus::IN_PRODUCTION;

        return $this;
    }

    /** La liberación requiere una revisión previa de Calidad. */
    public function markProductionCompleted(): self
    {
        if ($this->productionStatus !== ProductionOrderStatus::WAITING_QUALITY) {
            throw new \DomainException('La orden debe estar pendiente de Calidad para liberar la producción.');
        }

        $this->productionStatus = ProductionOrderStatus::PRODUCTION_COMPLETED;

        return $this;
    }

    public function markReadyForPickup(): self
    {
        $this->assertProductionCompletedBeforeDelivery();
        if ($this->deliveryStatus !== ServiceOrderDeliveryStatus::NOT_READY) {
            throw new \DomainException('La orden ya tiene un estado de entrega.');
        }

        $this->deliveryStatus = ServiceOrderDeliveryStatus::READY_FOR_PICKUP;

        return $this;
    }

    public function markReadyForShipment(): self
    {
        $this->assertProductionCompletedBeforeDelivery();
        if ($this->deliveryStatus !== ServiceOrderDeliveryStatus::NOT_READY) {
            throw new \DomainException('La orden ya tiene un estado de entrega.');
        }

        $this->deliveryStatus = ServiceOrderDeliveryStatus::READY_FOR_SHIPMENT;

        return $this;
    }

    public function markShipped(): self
    {
        if ($this->deliveryStatus !== ServiceOrderDeliveryStatus::READY_FOR_SHIPMENT) {
            throw new \DomainException('Solo una orden lista para envío puede marcarse como enviada.');
        }

        $this->deliveryStatus = ServiceOrderDeliveryStatus::SHIPPED;

        return $this;
    }

    public function markDelivered(): self
    {
        if (!in_array($this->deliveryStatus, [
            ServiceOrderDeliveryStatus::READY_FOR_PICKUP,
            ServiceOrderDeliveryStatus::SHIPPED,
        ], true)) {
            throw new \DomainException('La orden debe estar lista para recoger o enviada antes de entregarse.');
        }

        $this->deliveryStatus = ServiceOrderDeliveryStatus::DELIVERED;

        return $this;
    }

    /** @return array{planning_status: string, production_status: string, delivery_status: string, fulfillment_method: string, delivery_prepared: bool} */
    public function traceabilityStateSnapshot(): array
    {
        return [
            'planning_status' => $this->status->value,
            'production_status' => $this->productionStatus->value,
            'delivery_status' => $this->deliveryStatus->value,
            'fulfillment_method' => $this->fulfillmentMethod->value,
            'delivery_prepared' => $this->isDeliveryPrepared(),
        ];
    }

    /** Marca la raíz de concurrencia cuando cambia una operación hija. */
    public function touchTraceability(): self
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

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

    private function assertProductionCompletedBeforeDelivery(): void
    {
        if ($this->productionStatus !== ProductionOrderStatus::PRODUCTION_COMPLETED) {
            throw new \DomainException('La producción debe estar liberada por Calidad antes de preparar la entrega.');
        }
    }
}
