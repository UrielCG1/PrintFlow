<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Users\User;
use App\Enum\Inventory\InventoryShortageDecisionType;
use App\Repository\Inventory\InventoryShortageDecisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Decisión administrativa vigente o histórica ante el faltante de un apartado. */
#[ORM\Entity(repositoryClass: InventoryShortageDecisionRepository::class)]
#[ORM\Table(name: 'inventory_shortage_decisions')]
#[ORM\Index(name: 'idx_shortage_decision_commitment_active', columns: ['inventory_commitment_id', 'is_active'])]
class InventoryShortageDecision
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'inventory_commitment_id', nullable: false, onDelete: 'RESTRICT')] private InventoryCommitment $commitment;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'decided_by_user_id', nullable: false, onDelete: 'RESTRICT')] private User $decidedBy;
    #[ORM\Column(length: 40, enumType: InventoryShortageDecisionType::class)] private InventoryShortageDecisionType $type;
    #[ORM\Column(type: Types::TEXT)] private string $reason;
    #[ORM\Column(name: 'is_active')] private bool $isActive = true;
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt;
    #[ORM\Column(name: 'superseded_at', type: Types::DATETIME_IMMUTABLE, nullable: true)] private ?\DateTimeImmutable $supersededAt = null;

    public function __construct(InventoryCommitment $commitment, InventoryShortageDecisionType $type, string $reason, User $actor)
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 2000) {
            throw new \InvalidArgumentException('Explica la decisión de faltante con entre 5 y 2000 caracteres.');
        }
        $this->commitment = $commitment;
        $this->type = $type;
        $this->reason = $reason;
        $this->decidedBy = $actor;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getCommitment(): InventoryCommitment { return $this->commitment; }
    public function getType(): InventoryShortageDecisionType { return $this->type; }
    public function getReason(): string { return $this->reason; }
    public function isActive(): bool { return $this->isActive; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function supersede(): void
    {
        if (!$this->isActive) { return; }
        $this->isActive = false;
        $this->supersededAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
