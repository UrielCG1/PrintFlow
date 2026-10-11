<?php

declare(strict_types=1);

namespace App\Entity\Orders;

use App\Entity\Equipment\Equipment;
use App\Entity\Operations\Operation;
use App\Entity\Production\ProductionOperationExecution;
use App\Entity\Users\User;
use App\Entity\Production\ProductionReservation;
use App\Enum\Equipment\ProductionLoadUnit;
use App\Enum\Orders\ProductionEstimateKind;
use App\Repository\Orders\ServiceOrderOperationPlanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Una etapa planificada para una partida concreta de la orden.
 *
 * No representa la ejecución del trabajo ni consume materiales. Conserva la
 * selección manual de operación/equipo y sus snapshots para que los cambios
 * posteriores de catálogo no reescriban la planificación histórica.
 */
#[ORM\Entity(repositoryClass: ServiceOrderOperationPlanRepository::class)]
#[ORM\Table(name: 'service_order_operation_plans')]
#[ORM\UniqueConstraint(name: 'uniq_service_order_operation_plan_item_operation', columns: ['service_order_item_id', 'operation_id'])]
#[ORM\Index(name: 'idx_service_order_operation_plan_item_active_sequence', columns: ['service_order_item_id', 'is_active', 'sequence_number'])]
#[ORM\Index(name: 'idx_service_order_operation_plan_equipment_active', columns: ['equipment_id', 'is_active'])]
#[ORM\Index(name: 'idx_service_order_plan_operator', columns: ['operator_user_id'])]
#[ORM\Index(name: 'idx_service_order_plan_estimated_by', columns: ['estimated_by_user_id'])]
#[ORM\Index(name: 'idx_service_order_plan_actual_by', columns: ['actual_by_user_id'])]
#[ORM\HasLifecycleCallbacks]
class ServiceOrderOperationPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'operationPlans')]
    #[ORM\JoinColumn(name: 'service_order_item_id', nullable: false, onDelete: 'RESTRICT')]
    private ServiceOrderItem $serviceOrderItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'operation_id', nullable: false, onDelete: 'RESTRICT')]
    private Operation $operation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'equipment_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Equipment $equipment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'deactivated_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $deactivatedBy = null;

    #[ORM\Column(name: 'sequence_number', options: ['unsigned' => true])]
    private int $sequenceNumber;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'operation_snapshot', type: Types::JSON)]
    private array $operationSnapshot = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'equipment_snapshot', type: Types::JSON, nullable: true)]
    private ?array $equipmentSnapshot = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'route_step_snapshot', type: Types::JSON, nullable: true)]
    private ?array $routeStepSnapshot = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'operator_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $assignedOperator = null;

    #[ORM\Column(name: 'estimated_load_value', type: Types::DECIMAL, precision: 14, scale: 4, nullable: true)]
    private ?string $estimatedLoadValue = null;

    #[ORM\Column(name: 'estimated_load_unit', length: 20, nullable: true, enumType: ProductionLoadUnit::class)]
    private ?ProductionLoadUnit $estimatedLoadUnit = null;

    #[ORM\Column(name: 'estimate_kind', length: 16, nullable: true, enumType: ProductionEstimateKind::class)]
    private ?ProductionEstimateKind $estimateKind = null;

    #[ORM\Column(name: 'estimated_minutes', nullable: true, options: ['unsigned' => true])]
    private ?int $estimatedMinutes = null;

    #[ORM\Column(name: 'manual_estimate_reason', length: 500, nullable: true)]
    private ?string $manualEstimateReason = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'estimate_snapshot', type: Types::JSON, nullable: true)]
    private ?array $estimateSnapshot = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'estimated_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $estimatedBy = null;

    #[ORM\Column(name: 'estimated_at', type: 'utc_datetime_immutable', nullable: true, options: ['comment' => '(DC2Type:utc_datetime_immutable)'])]
    private ?\DateTimeImmutable $estimatedAt = null;

    #[ORM\Column(name: 'actual_minutes', nullable: true, options: ['unsigned' => true])]
    private ?int $actualMinutes = null;

    #[ORM\Column(name: 'actual_note', length: 500, nullable: true)]
    private ?string $actualNote = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actual_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $actualBy = null;

    #[ORM\Column(name: 'actual_at', type: 'utc_datetime_immutable', nullable: true, options: ['comment' => '(DC2Type:utc_datetime_immutable)'])]
    private ?\DateTimeImmutable $actualAt = null;

    /** @var Collection<int, ProductionReservation> */
    #[ORM\OneToMany(mappedBy: 'plan', targetEntity: ProductionReservation::class, cascade: ['persist'])]
    #[ORM\OrderBy(['segmentNumber' => 'ASC'])]
    private Collection $reservations;

    /** @var Collection<int, ProductionOperationExecution> */
    #[ORM\OneToMany(mappedBy: 'plan', targetEntity: ProductionOperationExecution::class, cascade: ['persist'])]
    #[ORM\OrderBy(['attemptNumber' => 'ASC', 'id' => 'ASC'])]
    private Collection $executions;

    #[ORM\Column(name: 'condition_applied', nullable: true)]
    private ?bool $conditionApplied = null;

    #[ORM\Column(name: 'blocked_reason', length: 255, nullable: true)]
    private ?string $blockedReason = null;

    #[ORM\Column(name: 'deactivated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->reservations = new ArrayCollection();
        $this->executions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getServiceOrderItem(): ServiceOrderItem
    {
        return $this->serviceOrderItem;
    }

    public function setServiceOrderItem(ServiceOrderItem $serviceOrderItem): self
    {
        $this->serviceOrderItem = $serviceOrderItem;

        return $this;
    }

    public function getOperation(): Operation
    {
        return $this->operation;
    }

    public function setOperation(Operation $operation): self
    {
        $this->operation = $operation;

        return $this;
    }

    public function getEquipment(): ?Equipment
    {
        return $this->equipment;
    }

    /** @param array<string, mixed>|null $equipmentSnapshot */
    public function setEquipment(?Equipment $equipment, ?array $equipmentSnapshot): self
    {
        $this->equipment = $equipment;
        $this->equipmentSnapshot = $equipment === null ? null : $equipmentSnapshot;

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

    public function getDeactivatedBy(): ?User
    {
        return $this->deactivatedBy;
    }

    public function getAssignedOperator(): ?User { return $this->assignedOperator; }
    public function assignOperator(?User $operator): self { $this->assignedOperator = $operator; return $this; }
    public function getEstimatedLoadValue(): ?string { return $this->estimatedLoadValue; }
    public function getEstimatedLoadUnit(): ?ProductionLoadUnit { return $this->estimatedLoadUnit; }
    public function getEstimateKind(): ?ProductionEstimateKind { return $this->estimateKind; }
    public function getEstimatedMinutes(): ?int { return $this->estimatedMinutes; }
    public function getManualEstimateReason(): ?string { return $this->manualEstimateReason; }
    /** @return array<string, mixed>|null */ public function getEstimateSnapshot(): ?array { return $this->estimateSnapshot; }
    public function getEstimatedBy(): ?User { return $this->estimatedBy; }
    public function getEstimatedAt(): ?\DateTimeImmutable { return $this->estimatedAt; }
    public function getActualMinutes(): ?int { return $this->actualMinutes; }
    public function getActualNote(): ?string { return $this->actualNote; }
    public function getActualBy(): ?User { return $this->actualBy; }
    public function getActualAt(): ?\DateTimeImmutable { return $this->actualAt; }
    /** @return Collection<int, ProductionReservation> */ public function getReservations(): Collection { return $this->reservations; }
    public function hasActiveReservations(): bool
    {
        foreach ($this->reservations as $reservation) {
            if ($reservation->isActive()) { return true; }
        }
        return false;
    }
    public function addReservation(ProductionReservation $reservation): self
    {
        if (!$this->reservations->contains($reservation)) { $this->reservations->add($reservation); }
        return $this;
    }

    /** @return Collection<int, ProductionOperationExecution> */
    public function getExecutions(): Collection
    {
        return $this->executions;
    }

    public function addExecution(ProductionOperationExecution $execution): self
    {
        if ($execution->getPlan() !== $this) {
            throw new \DomainException('El intento no pertenece a esta operación planificada.');
        }
        if (!$this->executions->contains($execution)) {
            $this->executions->add($execution);
        }

        return $this;
    }

    public function nextExecutionAttemptNumber(): int
    {
        $highest = 0;
        foreach ($this->executions as $execution) {
            $highest = max($highest, $execution->getAttemptNumber());
        }

        return $highest + 1;
    }

    public function hasOpenExecution(): bool
    {
        foreach ($this->executions as $execution) {
            if ($execution->isOpen()) {
                return true;
            }
        }

        return false;
    }

    public function hasCompletedExecution(): bool
    {
        foreach ($this->executions as $execution) {
            if ($execution->getStatus() === \App\Enum\Production\ProductionOperationExecutionStatus::COMPLETED) {
                return true;
            }
        }

        return false;
    }
    public function clearEstimate(): self
    {
        $this->estimatedLoadValue = null;
        $this->estimatedLoadUnit = null;
        $this->estimateKind = null;
        $this->estimatedMinutes = null;
        $this->manualEstimateReason = null;
        $this->estimateSnapshot = null;
        $this->estimatedBy = null;
        $this->estimatedAt = null;
        return $this;
    }

    /** @param array<string, mixed> $snapshot */
    public function setCalculatedEstimate(string $load, ProductionLoadUnit $unit, int $minutes, array $snapshot, User $actor): self
    {
        if ($minutes < 1) { throw new \InvalidArgumentException('La duración estimada debe ser positiva.'); }
        $this->estimatedLoadValue = self::positiveDecimal($load);
        $this->estimatedLoadUnit = $unit;
        $this->estimateKind = ProductionEstimateKind::CALCULATED;
        $this->estimatedMinutes = $minutes;
        $this->manualEstimateReason = null;
        $this->estimateSnapshot = $snapshot;
        $this->estimatedBy = $actor;
        $this->estimatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }

    /** @param array<string, mixed> $availableData */
    public function setManualEstimate(?string $load, ?ProductionLoadUnit $unit, int $minutes, string $reason, array $availableData, User $actor): self
    {
        if ($minutes < 1 || $minutes > 525600) { throw new \InvalidArgumentException('La estimación manual debe estar entre 1 minuto y un año.'); }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) { throw new \InvalidArgumentException('El motivo debe tener entre 5 y 500 caracteres.'); }
        $this->estimatedLoadValue = $load !== null && trim($load) !== '' ? self::positiveDecimal($load) : null;
        $this->estimatedLoadUnit = $this->estimatedLoadValue === null ? null : $unit;
        $this->estimateKind = ProductionEstimateKind::MANUAL;
        $this->estimatedMinutes = $minutes;
        $this->manualEstimateReason = $reason;
        $this->estimateSnapshot = ['version' => 'manual-v1', 'available_data' => $availableData];
        $this->estimatedBy = $actor;
        $this->estimatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }

    public function recordActualMinutes(int $minutes, string $note, User $actor): self
    {
        if ($minutes < 0 || $minutes > 525600) { throw new \InvalidArgumentException('El tiempo real debe estar entre 0 y un año.'); }
        $note = trim($note);
        if (mb_strlen($note) < 5 || mb_strlen($note) > 500) { throw new \InvalidArgumentException('El comentario debe tener entre 5 y 500 caracteres.'); }
        $this->actualMinutes = $minutes;
        $this->actualNote = $note;
        $this->actualBy = $actor;
        $this->actualAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }

    private static function positiveDecimal(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (!is_numeric($value) || (float) $value <= 0 || (float) $value > 999999999) {
            throw new \InvalidArgumentException('La carga física debe ser un número positivo.');
        }
        return number_format((float) $value, 4, '.', '');
    }

    public function getSequenceNumber(): int
    {
        return $this->sequenceNumber;
    }

    public function setSequenceNumber(int $sequenceNumber): self
    {
        if ($sequenceNumber < 1) {
            throw new \InvalidArgumentException('La secuencia de una operación debe ser mayor que cero.');
        }

        $this->sequenceNumber = $sequenceNumber;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /** @return array<string, mixed> */
    public function getOperationSnapshot(): array
    {
        return $this->operationSnapshot;
    }

    /** @param array<string, mixed> $operationSnapshot */
    public function setOperationSnapshot(array $operationSnapshot): self
    {
        $this->operationSnapshot = $operationSnapshot;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getEquipmentSnapshot(): ?array
    {
        return $this->equipmentSnapshot;
    }

    /** @return array<string, mixed>|null */
    public function getRouteStepSnapshot(): ?array
    {
        return $this->routeStepSnapshot;
    }

    /** @param array<string, mixed>|null $snapshot */
    public function setRouteStepSnapshot(?array $snapshot): self
    {
        $this->routeStepSnapshot = $snapshot;

        return $this;
    }

    public function getConditionApplied(): ?bool
    {
        return $this->conditionApplied;
    }

    public function setConditionApplied(?bool $conditionApplied): self
    {
        $this->conditionApplied = $conditionApplied;

        return $this;
    }

    public function getBlockedReason(): ?string
    {
        return $this->blockedReason;
    }

    public function setBlockedReason(?string $blockedReason): self
    {
        $blockedReason = trim((string) $blockedReason);
        $this->blockedReason = $blockedReason !== '' ? mb_substr($blockedReason, 0, 255) : null;

        return $this;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function deactivate(User $actor): self
    {
        if (!$this->isActive) {
            return $this;
        }

        $this->isActive = false;
        $this->deactivatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->deactivatedBy = $actor;

        return $this;
    }

    public function reactivate(): self
    {
        $this->isActive = true;
        $this->deactivatedAt = null;
        $this->deactivatedBy = null;

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
