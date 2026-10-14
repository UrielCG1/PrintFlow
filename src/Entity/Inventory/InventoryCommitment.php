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
    /**
     * Cantidad que sigue cubierta por el apartado, ya sea porque continúa
     * reservada o porque ya se aplicó a un consumo real. Las liberaciones no
     * cuentan como cobertura vigente.
     */
    public function getEffectiveCoveredQuantity(): string
    {
        $covered = BigDecimal::of($this->committedQuantity)->minus($this->releasedQuantity);
        if ($covered->isLessThan(BigDecimal::zero())) {
            throw new \LogicException('El apartado tiene más material liberado que material comprometido.');
        }

        return $covered->toScale(6)->__toString();
    }

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

    /**
     * Ajusta una necesidad que todavía no ha terminado de consumirse.
     *
     * La reducción nunca modifica un consumo histórico: solamente libera la
     * porción que continúa apartada. La ampliación deja el nuevo faltante
     * visible para que el manager intente cubrirlo bajo bloqueo de saldo.
     *
     * @return string cantidad que debe devolverse al saldo comprometido de la variante
     */
    public function reconcileRequestedQuantity(string $requestedQuantity): string
    {
        if ($this->status === InventoryCommitmentStatus::SUBSTITUTED) {
            throw new \DomainException('Un apartado sustituido requiere una nueva revisión técnica antes de modificar su necesidad.');
        }

        $requested = self::nonNegativeDecimal($requestedQuantity, 'La cantidad requerida conciliada');
        $consumed = BigDecimal::of($this->consumedQuantity);
        if (BigDecimal::of($requested)->isLessThan($consumed)) {
            throw new \DomainException('La nueva necesidad no puede ser menor que el material ya consumido.');
        }

        $effectiveCovered = BigDecimal::of($this->getEffectiveCoveredQuantity());
        $targetCovered = $effectiveCovered->isGreaterThan(BigDecimal::of($requested))
            ? BigDecimal::of($requested)
            : $effectiveCovered;
        $released = $effectiveCovered->minus($targetCovered)->toScale(6);

        if (!$released->isZero()) {
            $this->releasedQuantity = BigDecimal::of($this->releasedQuantity)
                ->plus($released)
                ->toScale(6)
                ->__toString();
        }

        $this->requestedQuantity = $requested;
        $currentCovered = BigDecimal::of($this->getEffectiveCoveredQuantity());
        $this->shortageQuantity = BigDecimal::of($requested)
            ->minus($currentCovered)
            ->toScale(6)
            ->__toString();
        $this->refreshCoverageStatus();

        return $released->__toString();
    }
    /** Cubre una parte del faltante con material físico recién recibido y lo deja apartado. */
    public function coverShortageQuantity(string $quantity): void
    {
        if (!in_array($this->status, [InventoryCommitmentStatus::PARTIAL, InventoryCommitmentStatus::SHORTAGE], true)) {
            throw new \DomainException('Solo un apartado con faltante puede recibir cobertura adicional.');
        }
        $quantity = self::positiveDecimal($quantity, 'La cobertura adicional');
        $shortage = BigDecimal::of($this->shortageQuantity);
        if (BigDecimal::of($quantity)->isGreaterThan($shortage)) {
            throw new \DomainException('La cobertura adicional supera el faltante pendiente del apartado.');
        }
        $this->committedQuantity = BigDecimal::of($this->committedQuantity)->plus($quantity)->toScale(6)->__toString();
        $this->shortageQuantity = $shortage->minus($quantity)->toScale(6)->__toString();
        $this->refreshCoverageStatus();
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

    private static function nonNegativeDecimal(string $value, string $field): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field.' debe ser una cantidad no negativa con máximo seis decimales.');
        }

        return BigDecimal::of($value)->toScale(6)->__toString();
    }

    private function refreshCoverageStatus(): void
    {
        if ($this->status === InventoryCommitmentStatus::SUBSTITUTED) {
            return;
        }

        if (BigDecimal::of($this->shortageQuantity)->isZero()) {
            $this->status = InventoryCommitmentStatus::COVERED;

            return;
        }

        $this->status = BigDecimal::of($this->getEffectiveCoveredQuantity())->isZero()
            ? InventoryCommitmentStatus::SHORTAGE
            : InventoryCommitmentStatus::PARTIAL;
    }
}
