<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Production\ProductionMaterialUsage;
use App\Repository\Inventory\InventoryCommitmentConsumptionRepository;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Immutable allocation of actual physical consumption against an order commitment. */
#[ORM\Entity(repositoryClass: InventoryCommitmentConsumptionRepository::class)]
#[ORM\Table(name: 'inventory_commitment_consumptions')]
#[ORM\Index(name: 'idx_commitment_consumption_commitment', columns: ['inventory_commitment_id', 'created_at'])]
class InventoryCommitmentConsumption
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'inventory_commitment_id', nullable: false, onDelete: 'RESTRICT')]
    private InventoryCommitment $commitment;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'production_material_usage_id', nullable: false, onDelete: 'RESTRICT')]
    private ProductionMaterialUsage $usage;

    #[ORM\Column(name: 'allocated_quantity', type: Types::DECIMAL, precision: 20, scale: 6)]
    private string $allocatedQuantity;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(InventoryCommitment $commitment, ProductionMaterialUsage $usage, string $allocatedQuantity)
    {
        $usageVariant = $usage->getVariant();
        $commitmentVariant = $commitment->getVariant();
        if ($usageVariant === null || ($usageVariant !== $commitmentVariant && ($usageVariant->getId() === null || $usageVariant->getId() !== $commitmentVariant->getId()))) {
            throw new \DomainException('El consumo debe corresponder a la misma variante del apartado.');
        }
        $allocatedQuantity = trim(str_replace(',', '.', $allocatedQuantity));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $allocatedQuantity) !== 1 || BigDecimal::of($allocatedQuantity)->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new \InvalidArgumentException('La cantidad aplicada al apartado debe ser positiva.');
        }

        $this->commitment = $commitment;
        $this->usage = $usage;
        $this->allocatedQuantity = BigDecimal::of($allocatedQuantity)->toScale(6)->__toString();
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getAllocatedQuantity(): string
    {
        return $this->allocatedQuantity;
    }
}
