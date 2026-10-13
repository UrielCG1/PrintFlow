<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Materials\MaterialVariant;
use App\Entity\Users\User;
use App\Enum\Inventory\InventoryCycleCountStatus;
use App\Repository\Inventory\InventoryCycleCountRepository;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Immutable count reading for one QR unit, with an administrative review trail. */
#[ORM\Entity(repositoryClass: InventoryCycleCountRepository::class)]
#[ORM\Table(name: 'inventory_cycle_counts')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_cycle_count_idempotency', columns: ['idempotency_key'])]
#[ORM\Index(name: 'idx_inventory_cycle_count_status_counted', columns: ['status', 'counted_at'])]
#[ORM\Index(name: 'idx_inventory_cycle_count_stock_status', columns: ['stock_unit_id', 'status'])]
class InventoryCycleCount
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'stock_unit_id', nullable: false, onDelete: 'RESTRICT')]
    private InventoryStockUnit $stockUnit;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'material_variant_id', nullable: false, onDelete: 'RESTRICT')]
    private MaterialVariant $variant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'requested_by_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $requestedBy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'reviewed_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $reviewedBy = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'inventory_movement_id', nullable: true, onDelete: 'RESTRICT')]
    private ?InventoryMovement $movement = null;

    #[ORM\Column(name: 'idempotency_key', length: 100)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'command_fingerprint', length: 64)]
    private string $commandFingerprint;

    #[ORM\Column(name: 'status', length: 20, enumType: InventoryCycleCountStatus::class)]
    private InventoryCycleCountStatus $status = InventoryCycleCountStatus::PENDING_REVIEW;

    #[ORM\Column(name: 'expected_quantity', type: Types::DECIMAL, precision: 20, scale: 6)]
    private string $expectedQuantity;

    #[ORM\Column(name: 'stock_unit_version')]
    private int $stockUnitVersion;

    #[ORM\Column(name: 'counted_quantity', type: Types::DECIMAL, precision: 20, scale: 6)]
    private string $countedQuantity;

    #[ORM\Column(name: 'difference_quantity', type: Types::DECIMAL, precision: 20, scale: 6)]
    private string $differenceQuantity;

    #[ORM\Column(name: 'note', length: 500, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'review_note', length: 500, nullable: true)]
    private ?string $reviewNote = null;

    #[ORM\Column(name: 'counted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $countedAt;

    #[ORM\Column(name: 'reviewed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    public function __construct(
        InventoryStockUnit $stockUnit,
        User $requestedBy,
        string $idempotencyKey,
        string $commandFingerprint,
        string $countedQuantity,
        ?string $note = null,
    ) {
        $idempotencyKey = trim($idempotencyKey);
        if (preg_match('/^[A-Za-z0-9._:-]{8,100}$/D', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException('La clave de idempotencia del conteo no tiene un formato válido.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $commandFingerprint) !== 1) {
            throw new \InvalidArgumentException('La huella del conteo no tiene un formato válido.');
        }

        $this->stockUnit = $stockUnit;
        $this->variant = $stockUnit->getVariant();
        $this->requestedBy = $requestedBy;
        $this->idempotencyKey = $idempotencyKey;
        $this->commandFingerprint = $commandFingerprint;
        $this->expectedQuantity = $stockUnit->getRemainingQuantity();
        $this->stockUnitVersion = $stockUnit->getInventoryVersion();
        $this->countedQuantity = self::nonNegativeDecimal($countedQuantity, 'La cantidad contada');
        $this->differenceQuantity = BigDecimal::of($this->countedQuantity)->minus($this->expectedQuantity)->toScale(6)->__toString();
        $this->note = self::optionalText($note, 'La observación del conteo');
        $this->countedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getStockUnit(): InventoryStockUnit { return $this->stockUnit; }
    public function getVariant(): MaterialVariant { return $this->variant; }
    public function getRequestedBy(): User { return $this->requestedBy; }
    public function getReviewedBy(): ?User { return $this->reviewedBy; }
    public function getMovement(): ?InventoryMovement { return $this->movement; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getCommandFingerprint(): string { return $this->commandFingerprint; }
    public function getStatus(): InventoryCycleCountStatus { return $this->status; }
    public function getExpectedQuantity(): string { return $this->expectedQuantity; }
    public function getStockUnitVersion(): int { return $this->stockUnitVersion; }
    public function getCountedQuantity(): string { return $this->countedQuantity; }
    public function getDifferenceQuantity(): string { return $this->differenceQuantity; }
    public function getNote(): ?string { return $this->note; }
    public function getReviewNote(): ?string { return $this->reviewNote; }
    public function getCountedAt(): \DateTimeImmutable { return $this->countedAt; }
    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }
    public function isPendingReview(): bool { return $this->status === InventoryCycleCountStatus::PENDING_REVIEW; }
    public function isApproved(): bool { return $this->status === InventoryCycleCountStatus::APPROVED; }
    public function isStale(): bool { return $this->status === InventoryCycleCountStatus::STALE; }

    public function approve(User $reviewer, ?InventoryMovement $movement, ?string $reviewNote): void
    {
        $this->assertPending();
        if (!BigDecimal::of($this->differenceQuantity)->isZero()) {
            if (self::optionalText($reviewNote, 'El motivo del ajuste') === null) {
                throw new \InvalidArgumentException('Indica el motivo del ajuste de inventario.');
            }
            if (!$movement instanceof InventoryMovement) {
                throw new \LogicException('Un conteo con diferencia debe conservar su movimiento de ajuste.');
            }
        }
        $this->status = InventoryCycleCountStatus::APPROVED;
        $this->reviewedBy = $reviewer;
        $this->movement = $movement;
        $this->reviewNote = self::optionalText($reviewNote, 'El motivo del ajuste');
        $this->reviewedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function reject(User $reviewer, string $reviewNote): void
    {
        $this->assertPending();
        $reviewNote = self::optionalText($reviewNote, 'El motivo del rechazo') ?? '';
        if ($reviewNote === '') {
            throw new \InvalidArgumentException('Indica el motivo para rechazar el conteo.');
        }
        $this->status = InventoryCycleCountStatus::REJECTED;
        $this->reviewedBy = $reviewer;
        $this->reviewNote = $reviewNote;
        $this->reviewedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function markStale(User $reviewer): void
    {
        $this->assertPending();
        $this->status = InventoryCycleCountStatus::STALE;
        $this->reviewedBy = $reviewer;
        $this->reviewNote = 'El saldo cambió después de la lectura; se requiere un reconteo.';
        $this->reviewedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function assertPending(): void
    {
        if (!$this->isPendingReview()) {
            throw new \DomainException('El conteo ya fue revisado y no puede procesarse otra vez.');
        }
    }

    private static function nonNegativeDecimal(string $value, string $field): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field.' no tiene un formato válido.');
        }

        return BigDecimal::of($value)->toScale(6)->__toString();
    }

    private static function optionalText(?string $value, string $field): ?string
    {
        $value = trim((string) $value);
        if (mb_strlen($value) > 500) {
            throw new \InvalidArgumentException($field.' no puede superar 500 caracteres.');
        }

        return $value !== '' ? $value : null;
    }
}
