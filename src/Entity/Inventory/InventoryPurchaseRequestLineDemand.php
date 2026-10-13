<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Snapshot of a waiting order shortage included in one internal purchase line. */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_purchase_request_line_demands')]
#[ORM\UniqueConstraint(name: 'uniq_purchase_request_line_demand', columns: ['purchase_request_line_id', 'inventory_commitment_id'])]
#[ORM\Index(name: 'idx_purchase_request_demand_commitment', columns: ['inventory_commitment_id'])]
class InventoryPurchaseRequestLineDemand
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'demands')]
    #[ORM\JoinColumn(name: 'purchase_request_line_id', nullable: false, onDelete: 'RESTRICT')]
    private InventoryPurchaseRequestLine $purchaseRequestLine;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'inventory_commitment_id', nullable: false, onDelete: 'RESTRICT')]
    private InventoryCommitment $commitment;

    #[ORM\Column(name: 'planned_quantity', type: Types::DECIMAL, precision: 20, scale: 6)]
    private string $plannedQuantity;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(InventoryPurchaseRequestLine $purchaseRequestLine, InventoryCommitment $commitment, string $plannedQuantity)
    {
        if ($purchaseRequestLine->getVariant() !== $commitment->getVariant()) {
            throw new \DomainException('La demanda vinculada debe usar la misma presentación de material que la compra.');
        }
        $plannedQuantity = trim(str_replace(',', '.', $plannedQuantity));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $plannedQuantity) !== 1 || BigDecimal::of($plannedQuantity)->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new \InvalidArgumentException('La demanda vinculada debe ser mayor que cero y tener máximo seis decimales.');
        }

        $this->purchaseRequestLine = $purchaseRequestLine;
        $this->commitment = $commitment;
        $this->plannedQuantity = BigDecimal::of($plannedQuantity)->toScale(6)->__toString();
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getPurchaseRequestLine(): InventoryPurchaseRequestLine { return $this->purchaseRequestLine; }
    public function getCommitment(): InventoryCommitment { return $this->commitment; }
    public function getPlannedQuantity(): string { return $this->plannedQuantity; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
