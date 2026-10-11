<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Equipment\Equipment;
use App\Entity\Users\User;
use App\Enum\Production\ProductionCalendarExceptionType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\Production\ProductionCalendarExceptionRepository::class)]
#[ORM\Table(name: 'production_calendar_exceptions')]
#[ORM\Index(name: 'idx_production_calendar_exception_window', columns: ['is_active', 'starts_at', 'ends_at'])]
#[ORM\Index(name: 'idx_production_calendar_exception_equipment', columns: ['equipment_id', 'is_active'])]
#[ORM\Index(name: 'idx_production_calendar_exception_created_by', columns: ['created_by_user_id'])]
#[ORM\Index(name: 'idx_production_calendar_exception_deactivated_by', columns: ['deactivated_by_user_id'])]
class ProductionCalendarException
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Equipment::class)]
    #[ORM\JoinColumn(name: 'equipment_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Equipment $equipment = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'deactivated_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $deactivatedBy = null;

    #[ORM\Column(length: 20, enumType: ProductionCalendarExceptionType::class)]
    private ProductionCalendarExceptionType $type;

    #[ORM\Column(name: 'starts_at', type: 'utc_datetime_immutable', options: ['comment' => '(DC2Type:utc_datetime_immutable)'])]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(name: 'ends_at', type: 'utc_datetime_immutable', options: ['comment' => '(DC2Type:utc_datetime_immutable)'])]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(length: 500)]
    private string $reason;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'utc_datetime_immutable', options: ['comment' => '(DC2Type:utc_datetime_immutable)'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'deactivated_at', type: 'utc_datetime_immutable', nullable: true, options: ['comment' => '(DC2Type:utc_datetime_immutable)'])]
    private ?\DateTimeImmutable $deactivatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getEquipment(): ?Equipment { return $this->equipment; }
    public function setEquipment(?Equipment $equipment): self { $this->equipment = $equipment; return $this; }
    public function getCreatedBy(): User { return $this->createdBy; }
    public function setCreatedBy(User $actor): self { $this->createdBy = $actor; return $this; }
    public function getDeactivatedBy(): ?User { return $this->deactivatedBy; }
    public function getType(): ProductionCalendarExceptionType { return $this->type; }
    public function setType(ProductionCalendarExceptionType $type): self { $this->type = $type; return $this; }
    public function getStartsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(\DateTimeImmutable $start): self { $this->startsAt = $start->setTimezone(new \DateTimeZone('UTC')); return $this; }
    public function getEndsAt(): \DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(\DateTimeImmutable $end): self { $this->endsAt = $end->setTimezone(new \DateTimeZone('UTC')); return $this; }
    public function getReason(): string { return $this->reason; }
    public function setReason(string $reason): self { $this->reason = trim($reason); return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function deactivate(User $actor): self
    {
        $this->isActive = false;
        $this->deactivatedBy = $actor;
        $this->deactivatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }
}
