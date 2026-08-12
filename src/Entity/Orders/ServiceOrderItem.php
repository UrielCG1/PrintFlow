<?php

declare(strict_types=1);

namespace App\Entity\Orders;

use App\Entity\Catalog\CommercialItem;
use App\Entity\Quotations\QuotationItem;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'service_order_items')]
#[ORM\UniqueConstraint(name: 'uniq_service_order_items_line_number', columns: ['service_order_id', 'line_number'])]
#[ORM\UniqueConstraint(name: 'uniq_service_order_items_source_quotation_item', columns: ['source_quotation_item_id'])]
#[ORM\Index(name: 'idx_service_order_items_commercial_item', columns: ['commercial_item_id'])]
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
    #[ORM\JoinColumn(name: 'source_quotation_item_id', nullable: false, onDelete: 'RESTRICT')]
    private QuotationItem $sourceQuotationItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'commercial_item_id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialItem $commercialItem;

    #[ORM\Column(name: 'line_number', options: ['unsigned' => true])]
    private int $lineNumber;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4)]
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

    public function getSourceQuotationItem(): QuotationItem
    {
        return $this->sourceQuotationItem;
    }

    public function setSourceQuotationItem(QuotationItem $sourceQuotationItem): self
    {
        $this->sourceQuotationItem = $sourceQuotationItem;

        return $this;
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

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): self
    {
        $quantity = trim(str_replace(',', '.', $quantity));

        if (preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,4})?$/D', $quantity) !== 1) {
            throw new \InvalidArgumentException('La cantidad de la partida no tiene un formato válido.');
        }

        [$integer, $decimal] = array_pad(explode('.', $quantity, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $normalized = $integer.'.'.str_pad($decimal, 4, '0');

        if ($normalized === '0.0000') {
            throw new \InvalidArgumentException('La cantidad de la partida debe ser mayor que cero.');
        }

        $this->quantity = $normalized;

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
