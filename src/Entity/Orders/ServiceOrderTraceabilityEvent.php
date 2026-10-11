<?php

declare(strict_types=1);

namespace App\Entity\Orders;

use App\Entity\Production\ProductionOperationExecution;
use App\Entity\Users\User;
use App\Repository\Orders\ServiceOrderTraceabilityEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Evento inmutable para la línea de tiempo operativa.
 *
 * No sustituye la bitácora administrativa: guarda el hecho de producción o
 * entrega con los estados, la correlación y las referencias vigentes al
 * momento de realizarlo.
 */
#[ORM\Entity(repositoryClass: ServiceOrderTraceabilityEventRepository::class)]
#[ORM\Table(name: 'service_order_traceability_events')]
#[ORM\UniqueConstraint(name: 'uniq_service_order_traceability_command', columns: ['command_id'])]
#[ORM\Index(name: 'idx_service_order_traceability_order_time', columns: ['service_order_id', 'occurred_at', 'id'])]
#[ORM\Index(name: 'idx_service_order_traceability_item_time', columns: ['service_order_item_id', 'occurred_at', 'id'])]
#[ORM\Index(name: 'idx_service_order_traceability_plan_time', columns: ['operation_plan_id', 'occurred_at', 'id'])]
#[ORM\Index(name: 'idx_service_order_traceability_execution_time', columns: ['operation_execution_id', 'occurred_at', 'id'])]
#[ORM\Index(name: 'idx_service_order_traceability_correlation', columns: ['correlation_id'])]
class ServiceOrderTraceabilityEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'service_order_id', nullable: false, onDelete: 'RESTRICT')]
    private ServiceOrder $serviceOrder;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'service_order_item_id', nullable: true, onDelete: 'RESTRICT')]
    private ?ServiceOrderItem $serviceOrderItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'operation_plan_id', nullable: true, onDelete: 'RESTRICT')]
    private ?ServiceOrderOperationPlan $operationPlan;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'operation_execution_id', nullable: true, onDelete: 'RESTRICT')]
    private ?ProductionOperationExecution $operationExecution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'actor_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $actor;

    #[ORM\Column(name: 'event_type', length: 120)]
    private string $eventType;

    #[ORM\Column(name: 'correlation_id', length: 64)]
    private string $correlationId;

    #[ORM\Column(name: 'command_id', length: 64, nullable: true)]
    private ?string $commandId;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'previous_state', type: Types::JSON, nullable: true)]
    private ?array $previousState;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'new_state', type: Types::JSON, nullable: true)]
    private ?array $newState;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $reason;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $context;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed>|null $previousState
     * @param array<string, mixed>|null $newState
     * @param array<string, mixed>|null $context
     */
    public function __construct(
        ServiceOrder $serviceOrder,
        string $eventType,
        ?User $actor,
        ?array $previousState,
        ?array $newState,
        ?ServiceOrderItem $serviceOrderItem = null,
        ?ServiceOrderOperationPlan $operationPlan = null,
        ?ProductionOperationExecution $operationExecution = null,
        ?string $reason = null,
        ?array $context = null,
        ?string $correlationId = null,
        ?string $commandId = null,
    ) {
        self::assertReferences($serviceOrder, $serviceOrderItem, $operationPlan, $operationExecution);

        $eventType = strtolower(trim($eventType));
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $eventType) !== 1) {
            throw new \InvalidArgumentException('El tipo de evento no tiene un formato válido.');
        }

        $reason = $reason !== null ? trim($reason) : null;
        if ($reason === '') {
            $reason = null;
        }
        if ($reason !== null && mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('El motivo del evento no puede superar 500 caracteres.');
        }

        $this->serviceOrder = $serviceOrder;
        $this->eventType = $eventType;
        $this->actor = $actor;
        $this->previousState = $previousState;
        $this->newState = $newState;
        $this->serviceOrderItem = $serviceOrderItem;
        $this->operationPlan = $operationPlan;
        $this->operationExecution = $operationExecution;
        $this->reason = $reason;
        $this->context = $context;
        $this->correlationId = self::normalizeIdentifier($correlationId ?? self::newCorrelationId(), 'La correlación');
        $this->commandId = $commandId !== null ? self::normalizeIdentifier($commandId, 'El identificador de comando') : null;
        $this->occurredAt = self::now();
        $this->createdAt = $this->occurredAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getServiceOrder(): ServiceOrder
    {
        return $this->serviceOrder;
    }

    public function getServiceOrderItem(): ?ServiceOrderItem
    {
        return $this->serviceOrderItem;
    }

    public function getOperationPlan(): ?ServiceOrderOperationPlan
    {
        return $this->operationPlan;
    }

    public function getOperationExecution(): ?ProductionOperationExecution
    {
        return $this->operationExecution;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getCommandId(): ?string
    {
        return $this->commandId;
    }

    /** @return array<string, mixed>|null */
    public function getPreviousState(): ?array
    {
        return $this->previousState;
    }

    /** @return array<string, mixed>|null */
    public function getNewState(): ?array
    {
        return $this->newState;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    /** @return array<string, mixed>|null */
    public function getContext(): ?array
    {
        return $this->context;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private static function assertReferences(
        ServiceOrder $serviceOrder,
        ?ServiceOrderItem $item,
        ?ServiceOrderOperationPlan $plan,
        ?ProductionOperationExecution $execution,
    ): void {
        if ($item !== null && $item->getServiceOrder() !== $serviceOrder) {
            throw new \DomainException('La partida del evento no pertenece a la orden.');
        }
        if ($plan !== null && ($item === null || $plan->getServiceOrderItem() !== $item)) {
            throw new \DomainException('El plan del evento no pertenece a la partida indicada.');
        }
        if ($execution !== null && ($plan === null || $execution->getPlan() !== $plan)) {
            throw new \DomainException('El intento del evento no pertenece al plan indicado.');
        }
    }

    private static function normalizeIdentifier(string $value, string $field): string
    {
        $value = trim($value);
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field.' no tiene un formato válido.');
        }

        return $value;
    }

    private static function newCorrelationId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
