<?php

declare(strict_types=1);

namespace App\Entity\Orders;

use App\Entity\Catalog\CommercialItem;
use App\Entity\Production\CommercialItemRouteVersion;
use App\Entity\Quotations\QuotationItem;
use App\Enum\Orders\ServiceOrderItemDesignStatus;
use App\Repository\Orders\ServiceOrderItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServiceOrderItemRepository::class)]
#[ORM\Table(name: 'service_order_items')]
#[ORM\UniqueConstraint(name: 'uniq_service_order_items_line_number', columns: ['service_order_id', 'line_number'])]
#[ORM\UniqueConstraint(name: 'uniq_service_order_items_source_quotation_item', columns: ['source_quotation_item_id'])]
#[ORM\UniqueConstraint(name: 'uniq_service_order_items_tracking_code', columns: ['tracking_code'])]
#[ORM\Index(name: 'idx_service_order_items_split_parent_position', fields: ['splitParentItem', 'splitPosition'])]
#[ORM\Index(name: 'idx_service_order_items_commercial_item', columns: ['commercial_item_id'])]
#[ORM\Index(name: 'idx_service_order_items_route_version', columns: ['route_version_id'])]
class ServiceOrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'service_order_id', nullable: false, onDelete: 'RESTRICT')]
    private ServiceOrder $serviceOrder;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'source_quotation_item_id', nullable: true, onDelete: 'RESTRICT')]
    private ?QuotationItem $sourceQuotationItem = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'split_parent_item_id', nullable: true, onDelete: 'RESTRICT')]
    private ?self $splitParentItem = null;

    #[ORM\Column(name: 'split_position', nullable: true, options: ['unsigned' => true])]
    private ?int $splitPosition = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'commercial_item_id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialItem $commercialItem;

    #[ORM\Column(name: 'line_number', options: ['unsigned' => true])]
    private int $lineNumber;

    #[ORM\Column(name: 'tracking_code', length: 64)]
    private string $trackingCode;

    #[ORM\Column(name: 'design_status', length: 30, enumType: ServiceOrderItemDesignStatus::class, options: ['default' => 'PENDING_REVIEW'])]
    private ServiceOrderItemDesignStatus $designStatus = ServiceOrderItemDesignStatus::PENDING_REVIEW;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 6)]
    private string $quantity;

    #[ORM\Column(name: 'unit_price', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $unitPrice;

    #[ORM\Column(name: 'line_subtotal', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $lineSubtotal;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'commercial_item_snapshot', type: Types::JSON)]
    private array $commercialItemSnapshot = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'price_rule_snapshot', type: Types::JSON, nullable: true)]
    private ?array $priceRuleSnapshot = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'request_details_snapshot', type: Types::JSON, nullable: true)]
    private ?array $requestDetailsSnapshot = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'source_file_snapshot', type: Types::JSON, nullable: true)]
    private ?array $sourceFileSnapshot = null;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'specifications_snapshot', type: Types::JSON)]
    private array $specificationsSnapshot = [];

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'production_specifications_snapshot', type: Types::JSON)]
    private array $productionSpecificationsSnapshot = [];

    #[ORM\Column(name: 'specification_schema_version', options: ['unsigned' => true, 'default' => 1])]
    private int $specificationSchemaVersion = 1;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'route_version_id', nullable: true, onDelete: 'RESTRICT')]
    private ?CommercialItemRouteVersion $routeVersion = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'route_snapshot', type: Types::JSON, nullable: true)]
    private ?array $routeSnapshot = null;

    #[ORM\Column(name: 'planning_issue', length: 255, nullable: true)]
    private ?string $planningIssue = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, ServiceOrderOperationPlan> */
    #[ORM\OneToMany(mappedBy: 'serviceOrderItem', targetEntity: ServiceOrderOperationPlan::class, cascade: ['persist'])]
    #[ORM\OrderBy(['sequenceNumber' => 'ASC', 'id' => 'ASC'])]
    private Collection $operationPlans;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->operationPlans = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getServiceOrder(): ServiceOrder
    {
        return $this->serviceOrder;
    }

    public function setServiceOrder(ServiceOrder $serviceOrder): self
    {
        $this->serviceOrder = $serviceOrder;

        return $this;
    }

    public function getSourceQuotationItem(): ?QuotationItem
    {
        return $this->sourceQuotationItem;
    }

    public function setSourceQuotationItem(QuotationItem $sourceQuotationItem): self
    {
        $this->sourceQuotationItem = $sourceQuotationItem;

        return $this;
    }

    public function getSplitParentItem(): ?self
    {
        return $this->splitParentItem;
    }

    public function setSplitParentItem(?self $splitParentItem): self
    {
        if ($splitParentItem === $this) {
            throw new \InvalidArgumentException('Una partida no puede ser hija de sí misma.');
        }

        $this->splitParentItem = $splitParentItem;

        return $this;
    }

    public function getSplitPosition(): ?int
    {
        return $this->splitPosition;
    }

    public function setSplitPosition(?int $splitPosition): self
    {
        if ($splitPosition !== null && $splitPosition < 1) {
            throw new \InvalidArgumentException('La posición de la partida dividida debe ser positiva.');
        }

        $this->splitPosition = $splitPosition;

        return $this;
    }

    public function getDisplayLineNumber(): string
    {
        $root = $this->splitParentItem ?? $this;
        if ($this->splitPosition === null) {
            return (string) $this->lineNumber;
        }

        return $root->getLineNumber().'.'.$this->splitPosition;
    }

    public function getCommercialItem(): CommercialItem
    {
        return $this->commercialItem;
    }

    public function setCommercialItem(CommercialItem $commercialItem): self
    {
        $this->commercialItem = $commercialItem;

        return $this;
    }

    public function getLineNumber(): int
    {
        return $this->lineNumber;
    }

    public function setLineNumber(int $lineNumber): self
    {
        if ($lineNumber < 1) {
            throw new \InvalidArgumentException('El número de partida debe ser mayor que cero.');
        }

        $this->lineNumber = $lineNumber;

        return $this;
    }

    public function getTrackingCode(): string
    {
        return $this->trackingCode;
    }

    public function setTrackingCode(string $trackingCode): self
    {
        $trackingCode = strtoupper(trim($trackingCode));
        if (preg_match('/^[A-Z0-9-]{1,64}$/D', $trackingCode) !== 1) {
            throw new \InvalidArgumentException('El código de trazabilidad de la partida no tiene un formato válido.');
        }

        $this->trackingCode = $trackingCode;

        return $this;
    }

    public function getDesignStatus(): ServiceOrderItemDesignStatus
    {
        return $this->designStatus;
    }

    public function beginDesignReview(): self
    {
        if ($this->designStatus === ServiceOrderItemDesignStatus::BLOCKED) {
            throw new \DomainException('La partida está bloqueada en Diseño y requiere una corrección previa.');
        }
        if ($this->designStatus === ServiceOrderItemDesignStatus::IN_REVIEW) {
            throw new \DomainException('La partida ya tiene una revisión de Diseño en curso.');
        }

        $this->designStatus = ServiceOrderItemDesignStatus::IN_REVIEW;

        return $this;
    }

    public function markReadyForPrint(): self
    {
        if ($this->designStatus !== ServiceOrderItemDesignStatus::IN_REVIEW) {
            throw new \DomainException('Inicia la revisión de Diseño antes de liberar la partida a Impresión.');
        }

        $this->designStatus = ServiceOrderItemDesignStatus::READY_FOR_PRINT;

        return $this;
    }

    public function blockDesign(?string $reason = null): self
    {
        $this->designStatus = ServiceOrderItemDesignStatus::BLOCKED;
        if ($reason !== null && trim($reason) !== '') {
            $this->setPlanningIssue($reason);
        }

        return $this;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): self
    {
        $value = trim(str_replace(',', '.', $quantity));

        if (preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('La cantidad de la partida no tiene un formato válido.');
        }

        $decimal = \Brick\Math\BigDecimal::of($value);
        if ($decimal->compareTo('0') <= 0) {
            throw new \InvalidArgumentException('La cantidad de la partida debe ser mayor que cero.');
        }

        $this->quantity = $decimal->toScale(6)->__toString();

        return $this;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(string $unitPrice): self
    {
        $this->unitPrice = $this->normalizeMoney($unitPrice, 'El precio unitario de la partida');

        return $this;
    }

    public function getLineSubtotal(): string
    {
        return $this->lineSubtotal;
    }

    public function setLineSubtotal(string $lineSubtotal): self
    {
        $this->lineSubtotal = $this->normalizeMoney($lineSubtotal, 'El subtotal de la partida');

        return $this;
    }

    /** @return array<string, mixed> */
    public function getCommercialItemSnapshot(): array
    {
        return $this->commercialItemSnapshot;
    }

    /** @param array<string, mixed> $commercialItemSnapshot */
    public function setCommercialItemSnapshot(array $commercialItemSnapshot): self
    {
        $this->commercialItemSnapshot = $commercialItemSnapshot;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getPriceRuleSnapshot(): ?array
    {
        return $this->priceRuleSnapshot;
    }

    /** @param array<string, mixed>|null $priceRuleSnapshot */
    public function setPriceRuleSnapshot(?array $priceRuleSnapshot): self
    {
        $this->priceRuleSnapshot = $priceRuleSnapshot;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getRequestDetailsSnapshot(): ?array
    {
        return $this->requestDetailsSnapshot;
    }

    /** @param array<string, mixed>|null $requestDetailsSnapshot */
    public function setRequestDetailsSnapshot(?array $requestDetailsSnapshot): self
    {
        $this->requestDetailsSnapshot = $requestDetailsSnapshot;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getSourceFileSnapshot(): ?array
    {
        return $this->sourceFileSnapshot;
    }

    /** @param array<string, mixed>|null $sourceFileSnapshot */
    public function setSourceFileSnapshot(?array $sourceFileSnapshot): self
    {
        $this->sourceFileSnapshot = $sourceFileSnapshot;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSpecificationsSnapshot(): array
    {
        return $this->specificationsSnapshot;
    }

    /** @param array<string, mixed> $specificationsSnapshot */
    public function setSpecificationsSnapshot(array $specificationsSnapshot): self
    {
        $this->specificationsSnapshot = $specificationsSnapshot;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getProductionSpecificationsSnapshot(): array
    {
        return $this->productionSpecificationsSnapshot;
    }

    /** @param array<string, mixed> $productionSpecificationsSnapshot */
    public function setProductionSpecificationsSnapshot(array $productionSpecificationsSnapshot): self
    {
        $this->productionSpecificationsSnapshot = $productionSpecificationsSnapshot;

        return $this;
    }

    public function getSpecificationSchemaVersion(): int
    {
        return $this->specificationSchemaVersion;
    }

    public function setSpecificationSchemaVersion(int $specificationSchemaVersion): self
    {
        if ($specificationSchemaVersion < 1) {
            throw new \InvalidArgumentException('La versión del esquema técnico debe ser positiva.');
        }

        $this->specificationSchemaVersion = $specificationSchemaVersion;

        return $this;
    }

    public function getRouteVersion(): ?CommercialItemRouteVersion
    {
        return $this->routeVersion;
    }

    /** @param array<string, mixed>|null $routeSnapshot */
    public function setRoute(?CommercialItemRouteVersion $routeVersion, ?array $routeSnapshot): self
    {
        if ($routeVersion !== null && $routeVersion->getCommercialItem() !== $this->commercialItem) {
            throw new \DomainException('La versión de ruta no pertenece al Producto de la partida.');
        }

        $this->routeVersion = $routeVersion;
        $this->routeSnapshot = $routeSnapshot;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getRouteSnapshot(): ?array
    {
        return $this->routeSnapshot;
    }

    public function getPlanningIssue(): ?string
    {
        return $this->planningIssue;
    }

    public function setPlanningIssue(?string $planningIssue): self
    {
        $planningIssue = trim((string) $planningIssue);
        $this->planningIssue = $planningIssue !== '' ? mb_substr($planningIssue, 0, 255) : null;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, ServiceOrderOperationPlan> */
    public function getOperationPlans(): Collection
    {
        return $this->operationPlans;
    }

    /** @return list<ServiceOrderOperationPlan> */
    public function getActiveOperationPlans(): array
    {
        return array_values(array_filter(
            $this->operationPlans->toArray(),
            static fn (ServiceOrderOperationPlan $plan): bool => $plan->isActive(),
        ));
    }

    public function addOperationPlan(ServiceOrderOperationPlan $plan): self
    {
        if (!$this->operationPlans->contains($plan)) {
            $this->operationPlans->add($plan);
            $plan->setServiceOrderItem($this);
        }

        return $this;
    }

    private function normalizeMoney(string $value, string $field): string
    {
        $value = trim(str_replace(',', '.', $value));

        if (preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field.' no tiene un formato válido.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';

        return $integer.'.'.str_pad($decimal, 2, '0');
    }
}
