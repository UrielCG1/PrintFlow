<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Users\User;
use App\Enum\Production\ProductionOperationExecutionStatus;
use App\Repository\Production\ProductionOperationExecutionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Evidencia de un intento de trabajo sobre una operación planificada.
 *
 * El plan no se altera para esconder un reintento. Cada reintento conserva un
 * número propio y, cuando procede, la referencia al intento que lo originó.
 */
#[ORM\Entity(repositoryClass: ProductionOperationExecutionRepository::class)]
#[ORM\Table(name: 'production_operation_executions')]
#[ORM\UniqueConstraint(name: 'uniq_production_execution_plan_attempt', columns: ['plan_id', 'attempt_number'])]
#[ORM\UniqueConstraint(name: 'uniq_production_execution_open_plan', columns: ['plan_id', 'open_marker'])]
#[ORM\Index(name: 'idx_production_execution_plan_status', columns: ['plan_id', 'status'])]
#[ORM\Index(name: 'idx_production_execution_assigned_status', columns: ['assigned_operator_user_id', 'status'])]
#[ORM\Index(name: 'idx_production_execution_origin', columns: ['origin_execution_id'])]
#[ORM\HasLifecycleCallbacks]
class ProductionOperationExecution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'executions')]
    #[ORM\JoinColumn(name: 'plan_id', nullable: false, onDelete: 'RESTRICT')]
    private ServiceOrderOperationPlan $plan;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'origin_execution_id', nullable: true, onDelete: 'RESTRICT')]
    private ?self $originExecution = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assigned_operator_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $assignedOperator;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(name: 'attempt_number', options: ['unsigned' => true])]
    private int $attemptNumber;

    #[ORM\Column(length: 20, enumType: ProductionOperationExecutionStatus::class)]
    private ProductionOperationExecutionStatus $status = ProductionOperationExecutionStatus::IN_PROGRESS;

    /**
     * Valor nulo al cerrar el intento. La restricción única junto con plan_id
     * permite muchos intentos cerrados y solamente uno abierto por operación.
     */
    #[ORM\Column(name: 'open_marker', nullable: true, options: ['unsigned' => true, 'default' => 1])]
    private ?int $openMarker = 1;

    #[ORM\Column(name: 'pause_reason', length: 500, nullable: true)]
    private ?string $pauseReason = null;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    /** Segundos efectivamente transcurridos en los intervalos activos del intento. */
    #[ORM\Column(name: 'active_seconds', options: ['unsigned' => true, 'default' => 0])]
    private int $activeSeconds = 0;

    /** Inicio del intervalo activo más reciente; queda nulo mientras está pausado o cerrado. */
    #[ORM\Column(name: 'active_segment_started_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activeSegmentStartedAt = null;

    #[ORM\Column(name: 'paused_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $pausedAt = null;

    #[ORM\Column(name: 'completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(name: 'completion_note', length: 500, nullable: true)]
    private ?string $completionNote = null;

    #[ORM\Version]
    #[ORM\Column(name: 'state_version', type: Types::INTEGER, options: ['unsigned' => true, 'default' => 1])]
    private int $stateVersion = 1;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        ServiceOrderOperationPlan $plan,
        User $assignedOperator,
        User $createdBy,
        int $attemptNumber,
        ?self $originExecution = null,
    ) {
        if ($attemptNumber < 1) {
            throw new \InvalidArgumentException('El número de intento debe ser positivo.');
        }

        if ($originExecution !== null && $originExecution->getPlan() !== $plan) {
            throw new \DomainException('El intento origen debe pertenecer a la misma operación planificada.');
        }

        $now = self::now();
        $this->plan = $plan;
        $this->assignedOperator = $assignedOperator;
        $this->createdBy = $createdBy;
        $this->attemptNumber = $attemptNumber;
        $this->originExecution = $originExecution;
        $this->startedAt = $now;
        $this->activeSegmentStartedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlan(): ServiceOrderOperationPlan
    {
        return $this->plan;
    }

    public function getOriginExecution(): ?self
    {
        return $this->originExecution;
    }

    public function getAssignedOperator(): User
    {
        return $this->assignedOperator;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function getStatus(): ProductionOperationExecutionStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return $this->openMarker !== null;
    }

    public function getPauseReason(): ?string
    {
        return $this->pauseReason;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getActiveSeconds(): int
    {
        return $this->activeSeconds;
    }

    public function getActiveSecondsAt(?\DateTimeImmutable $at = null): int
    {
        if ($this->status !== ProductionOperationExecutionStatus::IN_PROGRESS || $this->activeSegmentStartedAt === null) {
            return $this->activeSeconds;
        }

        $at = ($at ?? self::now())->setTimezone(new \DateTimeZone('UTC'));
        return $this->activeSeconds + max(0, $at->getTimestamp() - $this->activeSegmentStartedAt->getTimestamp());
    }

    public function getPausedAt(): ?\DateTimeImmutable
    {
        return $this->pausedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getCompletionNote(): ?string
    {
        return $this->completionNote;
    }

    public function getStateVersion(): int
    {
        return $this->stateVersion;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function pause(string $reason, ?\DateTimeImmutable $at = null): self
    {
        if ($this->status !== ProductionOperationExecutionStatus::IN_PROGRESS) {
            throw new \DomainException('Solo un intento en curso puede pausarse.');
        }

        $this->accumulateActiveSeconds($at);
        $this->status = ProductionOperationExecutionStatus::PAUSED;
        $this->pauseReason = self::normalizeNote($reason, 'El motivo de pausa');
        $this->pausedAt = self::now();

        return $this;
    }

    public function resume(?\DateTimeImmutable $at = null): self
    {
        if ($this->status !== ProductionOperationExecutionStatus::PAUSED) {
            throw new \DomainException('Solo un intento pausado puede reanudarse.');
        }

        $this->status = ProductionOperationExecutionStatus::IN_PROGRESS;
        $this->pauseReason = null;
        $this->pausedAt = null;
        $this->activeSegmentStartedAt = ($at ?? self::now())->setTimezone(new \DateTimeZone('UTC'));

        return $this;
    }

    public function complete(?string $note = null, ?\DateTimeImmutable $at = null): self
    {
        if ($this->status !== ProductionOperationExecutionStatus::IN_PROGRESS) {
            throw new \DomainException('Solo un intento en curso puede completarse.');
        }

        $this->accumulateActiveSeconds($at);
        $this->status = ProductionOperationExecutionStatus::COMPLETED;
        $this->openMarker = null;
        $this->completionNote = $note !== null ? self::normalizeNote($note, 'El comentario de cierre') : null;
        $this->completedAt = self::now();

        return $this;
    }

    public function cancel(string $reason, ?\DateTimeImmutable $at = null): self
    {
        if (!$this->status->isOpen()) {
            throw new \DomainException('Solo un intento abierto puede cancelarse.');
        }

        $this->accumulateActiveSeconds($at);
        $this->status = ProductionOperationExecutionStatus::CANCELLED;
        $this->openMarker = null;
        $this->completionNote = self::normalizeNote($reason, 'El motivo de cancelación');
        $this->completedAt = self::now();

        return $this;
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = self::now();
    }

    private static function normalizeNote(string $note, string $field): string
    {
        $note = trim($note);
        if (mb_strlen($note) < 5 || mb_strlen($note) > 500) {
            throw new \InvalidArgumentException($field.' debe tener entre 5 y 500 caracteres.');
        }

        return $note;
    }

    private function accumulateActiveSeconds(?\DateTimeImmutable $at): void
    {
        if ($this->status !== ProductionOperationExecutionStatus::IN_PROGRESS || $this->activeSegmentStartedAt === null) {
            return;
        }

        $at = ($at ?? self::now())->setTimezone(new \DateTimeZone('UTC'));
        if ($at < $this->activeSegmentStartedAt) {
            throw new \DomainException('La hora de la transición no puede ser anterior al último inicio activo.');
        }
        $this->activeSeconds += $at->getTimestamp() - $this->activeSegmentStartedAt->getTimestamp();
        $this->activeSegmentStartedAt = null;
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
