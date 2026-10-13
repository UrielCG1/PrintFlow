<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Application\Inventory\InventoryCoverage;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Users\User;
use App\Enum\Inventory\InventoryCommitmentStatus;
use App\Repository\Inventory\InventoryCommitmentRepository;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Apartado de una variante para una orden; no representa salida física. */
#[ORM\Entity(repositoryClass: InventoryCommitmentRepository::class)]
#[ORM\Table(name: 'inventory_commitments')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_commitment_order_variant', columns: ['service_order_id', 'material_variant_id'])]
#[ORM\Index(name: 'idx_inventory_commitment_status', columns: ['status', 'created_at'])]
class InventoryCommitment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'service_order_id', nullable: false, onDelete: 'RESTRICT')] private ServiceOrder $serviceOrder;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'material_variant_id', nullable: false, onDelete: 'RESTRICT')] private MaterialVariant $variant;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false, onDelete: 'RESTRICT')] private User $createdBy;
    #[ORM\Column(name: 'requested_quantity', type: Types::DECIMAL, precision: 20, scale: 6)] private string $requestedQuantity;
    #[ORM\Column(name: 'committed_quantity', type: Types::DECIMAL, precision: 20, scale: 6)] private string $committedQuantity;
    #[ORM\Column(name: 'consumed_quantity', type: Types::DECIMAL, precision: 20, scale: 6)] private string $consumedQuantity = '0.000000';
    #[ORM\Column(name: 'released_quantity', type: Types::DECIMAL, precision: 20, scale: 6)] private string $releasedQuantity = '0.000000';
    #[ORM\Column(name: 'shortage_quantity', type: Types::DECIMAL, precision: 20, scale: 6)] private string $shortageQuantity;
    #[ORM\Column(length: 20, enumType: InventoryCommitmentStatus::class)] private InventoryCommitmentStatus $status;
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt;

    public function __construct(ServiceOrder $order, MaterialVariant $variant, User $actor, InventoryCoverage $coverage)
    {
        $this->serviceOrder = $order; $this->variant = $variant; $this->createdBy = $actor;
        $this->requestedQuantity = $coverage->requiredQuantity; $this->committedQuantity = $coverage->coveredQuantity; $this->shortageQuantity = $coverage->shortageQuantity;
        $this->status = $coverage->isFullyCovered() ? InventoryCommitmentStatus::COVERED : ($coverage->hasPartialCoverage() ? InventoryCommitmentStatus::PARTIAL : InventoryCommitmentStatus::SHORTAGE);
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
    public function getId(): ?int { return $this->id; }
    public function getServiceOrder(): ServiceOrder { return $this->serviceOrder; }
    public function getVariant(): MaterialVariant { return $this->variant; }
    public function getRequestedQuantity(): string { return $this->requestedQuantity; }
    public function getCommittedQuantity(): string { return $this->committedQuantity; }
    public function getConsumedQuantity(): string { return $this->consumedQuantity; }
    public function getReleasedQuantity(): string { return $this->releasedQuantity; }
    public function getPendingQuantity(): string
    {
        $pending = BigDecimal::of($this->committedQuantity)->minus($this->consumedQuantity)->minus($this->releasedQuantity);
        if ($pending->isLessThan(BigDecimal::zero())) {
            throw new \LogicException('El apartado tiene más material conciliado que material comprometido.');
        }

        return $pending->toScale(6)->__toString();
    }
    public function getShortageQuantity(): string { return $this->shortageQuantity; }
    public function getStatus(): InventoryCommitmentStatus { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function recordConsumedQuantity(string $quantity): void
    {
        $quantity = self::positiveDecimal($quantity, 'La cantidad aplicada al apartado');
        if (BigDecimal::of($quantity)->isGreaterThan(BigDecimal::of($this->getPendingQuantity()))) {
            throw new \DomainException('El consumo aplicado supera el saldo pendiente del apartado.');
        }
        $this->consumedQuantity = BigDecimal::of($this->consumedQuantity)->plus($quantity)->toScale(6)->__toString();
    }

    public function releasePendingQuantity(): string
    {
        $pending = $this->getPendingQuantity();
        if (BigDecimal::of($pending)->isZero()) {
            return $pending;
        }
        $this->releasedQuantity = BigDecimal::of($this->releasedQuantity)->plus($pending)->toScale(6)->__toString();

        return $pending;
    }
    public function markShortageSubstituted(): void
    {
        if ($this->shortageQuantity === '0.000000') { throw new \DomainException('Solo un faltante puede sustituirse.'); }
        if ($this->status === InventoryCommitmentStatus::SUBSTITUTED) { throw new \DomainException('El faltante ya fue sustituido.'); }
        $this->status = InventoryCommitmentStatus::SUBSTITUTED;
    }

    private static function positiveDecimal(string $value, string $field): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $value) !== 1 || BigDecimal::of($value)->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new \InvalidArgumentException($field.' debe ser una cantidad positiva con máximo seis decimales.');
        }

        return BigDecimal::of($value)->toScale(6)->__toString();
    }
}
