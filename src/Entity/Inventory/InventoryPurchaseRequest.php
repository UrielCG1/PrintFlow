<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Users\User;
use App\Enum\Inventory\InventoryPurchaseRequestStatus;
use App\Repository\Inventory\InventoryPurchaseRequestRepository;
use Brick\Math\BigDecimal;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Internal procurement request; its quantities remain non-physical until a receipt is posted. */
#[ORM\Entity(repositoryClass: InventoryPurchaseRequestRepository::class)]
#[ORM\Table(name: 'inventory_purchase_requests')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_purchase_request_code', columns: ['request_code'])]
#[ORM\UniqueConstraint(name: 'uniq_inventory_purchase_request_idempotency', columns: ['idempotency_key'])]
#[ORM\Index(name: 'idx_inventory_purchase_request_status_created', columns: ['status', 'created_at'])]
class InventoryPurchaseRequest
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'request_code', length: 40)]
    private string $requestCode;

    #[ORM\Column(name: 'idempotency_key', length: 100)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'command_fingerprint', length: 64)]
    private string $commandFingerprint;

    #[ORM\Column(name: 'status', length: 20, enumType: InventoryPurchaseRequestStatus::class)]
    private InventoryPurchaseRequestStatus $status = InventoryPurchaseRequestStatus::DRAFT;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(name: 'tracking_reference', length: 100, nullable: true)]
    private ?string $trackingReference = null;

    /** Date promised by the supplier; it is intentionally separate from physical receipt. */
    #[ORM\Column(name: 'expected_receipt_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expectedReceiptOn = null;

    #[ORM\Column(name: 'notes', type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'tracking_started_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $trackingStartedAt = null;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'cancelled_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $cancelledBy = null;

    #[ORM\Column(name: 'cancelled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(name: 'cancellation_reason', type: Types::TEXT, nullable: true)]
    private ?string $cancellationReason = null;

    /** @var Collection<int, InventoryPurchaseRequestLine> */
    #[ORM\OneToMany(mappedBy: 'purchaseRequest', targetEntity: InventoryPurchaseRequestLine::class, cascade: ['persist'])]
    private Collection $lines;

    public function __construct(string $idempotencyKey, string $commandFingerprint, User $createdBy, ?string $notes = null)
    {
        $idempotencyKey = trim($idempotencyKey);
        if (preg_match('/^[A-Za-z0-9._:-]{8,100}$/D', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException('La clave de idempotencia de la solicitud de compra no tiene un formato válido.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $commandFingerprint) !== 1) {
            throw new \InvalidArgumentException('La huella de la solicitud de compra no tiene un formato válido.');
        }

        $this->requestCode = 'SOL-'.(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
        $this->idempotencyKey = $idempotencyKey;
        $this->commandFingerprint = $commandFingerprint;
        $this->createdBy = $createdBy;
        $this->notes = self::optionalText($notes, 4000, 'Las notas de la solicitud');
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getRequestCode(): string { return $this->requestCode; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getCommandFingerprint(): string { return $this->commandFingerprint; }
    public function getStatus(): InventoryPurchaseRequestStatus { return $this->status; }
    public function getCreatedBy(): User { return $this->createdBy; }
    public function getTrackingReference(): ?string { return $this->trackingReference; }
    public function getExpectedReceiptOn(): ?\DateTimeImmutable { return $this->expectedReceiptOn; }
    public function getNotes(): ?string { return $this->notes; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getTrackingStartedAt(): ?\DateTimeImmutable { return $this->trackingStartedAt; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function getCancelledBy(): ?User { return $this->cancelledBy; }
    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }
    public function getCancellationReason(): ?string { return $this->cancellationReason; }
    /** @return Collection<int, InventoryPurchaseRequestLine> */
    public function getLines(): Collection { return $this->lines; }
    public function isDraft(): bool { return $this->status === InventoryPurchaseRequestStatus::DRAFT; }
    public function isInProgress(): bool { return $this->status === InventoryPurchaseRequestStatus::IN_PROGRESS; }
    public function isOpen(): bool { return $this->status === InventoryPurchaseRequestStatus::DRAFT || $this->isInProgress(); }
    public function isCancelled(): bool { return $this->status === InventoryPurchaseRequestStatus::CANCELLED; }

    public function canBeCancelled(): bool
    {
        if (!$this->isOpen()) {
            return false;
        }
        foreach ($this->lines as $line) {
            if (BigDecimal::of($line->getReceivedQuantity())->isGreaterThan(BigDecimal::zero())) {
                return false;
            }
        }

        return true;
    }

    public function addLine(InventoryPurchaseRequestLine $line): void
    {
        if ($line->getPurchaseRequest() !== $this) {
            throw new \DomainException('La partida debe pertenecer a la misma solicitud de compra.');
        }
        foreach ($this->lines as $existing) {
            if ($existing->getVariant() === $line->getVariant()) {
                throw new \DomainException('Una solicitud de compra no puede repetir la misma presentación de material.');
            }
        }
        $this->lines->add($line);
    }

    public function beginTracking(?string $trackingReference, ?\DateTimeImmutable $expectedReceiptOn = null): void
    {
        if (!$this->isDraft()) {
            throw new \DomainException('Solo una solicitud preparada puede pasar a compra en seguimiento.');
        }
        if ($this->lines->isEmpty()) {
            throw new \DomainException('La solicitud de compra debe tener al menos una partida.');
        }
        $this->trackingReference = self::optionalText($trackingReference, 100, 'La referencia de compra');
        $this->expectedReceiptOn = self::dateOnly($expectedReceiptOn);
        $this->status = InventoryPurchaseRequestStatus::IN_PROGRESS;
        $this->trackingStartedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Supplier follow-up may change, but the requested quantities stay immutable after confirmation.
     */
    public function updateTracking(?string $trackingReference, ?\DateTimeImmutable $expectedReceiptOn, string $reason): void
    {
        if (!$this->isInProgress()) {
            throw new \DomainException('Solo una compra en seguimiento puede actualizar su fecha o referencia.');
        }

        self::requiredText($reason, 5, 1000, 'Explica el cambio de seguimiento');
        $this->trackingReference = self::optionalText($trackingReference, 100, 'La referencia de compra');
        $this->expectedReceiptOn = self::dateOnly($expectedReceiptOn);
    }

    /**
     * A tracked request can only be cancelled before an actual physical receipt exists.
     * This keeps QR-linked receipts and the procurement trail immutable.
     */
    public function cancel(User $actor, string $reason): void
    {
        if (!$this->isOpen()) {
            throw new \DomainException('Solo una solicitud preparada o en seguimiento puede cancelarse.');
        }
        if (!$this->canBeCancelled()) {
            throw new \DomainException('No se puede cancelar una compra que ya tiene recepciones físicas. Actualiza su seguimiento y recibe o concilia el saldo pendiente.');
        }

        $this->status = InventoryPurchaseRequestStatus::CANCELLED;
        $this->cancelledBy = $actor;
        $this->cancellationReason = self::requiredText($reason, 5, 2000, 'Explica la cancelación de compra');
        $this->cancelledAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** Earliest commitment date among the orders included when this request was prepared. */
    public function getRequiredByOn(): ?\DateTimeImmutable
    {
        $requiredBy = null;
        foreach ($this->lines as $line) {
            foreach ($line->getDemands() as $demand) {
                $candidate = $demand->getCommitment()->getServiceOrder()->getCommitmentDate();
                if ($candidate !== null && ($requiredBy === null || $candidate < $requiredBy)) {
                    $requiredBy = $candidate;
                }
            }
        }

        return $requiredBy;
    }

    public function isExpectedAfterRequiredDate(): bool
    {
        if (!$this->isInProgress()) {
            return false;
        }
        $requiredBy = $this->getRequiredByOn();

        return $this->expectedReceiptOn !== null
            && $requiredBy !== null
            && $this->expectedReceiptOn->format('Y-m-d') > $requiredBy->format('Y-m-d');
    }

    public function isExpectedReceiptOverdue(?\DateTimeImmutable $today = null): bool
    {
        if (!$this->isInProgress() || $this->expectedReceiptOn === null) {
            return false;
        }
        $today ??= new \DateTimeImmutable('today', new \DateTimeZone('America/Mexico_City'));

        return $this->expectedReceiptOn->format('Y-m-d') < $today->format('Y-m-d');
    }

    public function closeIfFullyReceived(): void
    {
        if (!$this->isInProgress()) {
            return;
        }
        foreach ($this->lines as $line) {
            if (!$line->isFullyReceived()) {
                return;
            }
        }
        $this->status = InventoryPurchaseRequestStatus::CLOSED;
        $this->closedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private static function optionalText(?string $value, int $maxLength, string $field): ?string
    {
        $value = trim((string) $value);
        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException($field.' no puede superar '.$maxLength.' caracteres.');
        }

        return $value !== '' ? $value : null;
    }

    private static function requiredText(string $value, int $minLength, int $maxLength, string $field): string
    {
        $value = trim($value);
        $length = mb_strlen($value);
        if ($length < $minLength || $length > $maxLength) {
            throw new \InvalidArgumentException($field.' debe tener entre '.$minLength.' y '.$maxLength.' caracteres.');
        }

        return $value;
    }

    private static function dateOnly(?\DateTimeImmutable $value): ?\DateTimeImmutable
    {
        return $value?->setTime(0, 0);
    }
}
