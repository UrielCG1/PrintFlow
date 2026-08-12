<?php

declare(strict_types=1);

namespace App\Entity\Orders;

use App\Entity\Equipment\Equipment;
use App\Entity\Operations\Operation;
use App\Entity\Users\User;
use App\Repository\Orders\ServiceOrderOperationPlanRepository;
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
