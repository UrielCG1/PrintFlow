<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Equipment\Equipment;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Users\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\Production\ProductionReservationRepository::class)]
#[ORM\Table(name: 'production_reservations')]
#[ORM\UniqueConstraint(name: 'uniq_production_reservation_plan_segment', columns: ['plan_id', 'segment_number'])]
#[ORM\Index(name: 'idx_production_reservation_equipment_window', columns: ['equipment_id', 'is_active', 'starts_at', 'ends_at'])]
#[ORM\Index(name: 'idx_production_reservation_plan_active', columns: ['plan_id', 'is_active'])]
#[ORM\Index(name: 'idx_production_reservation_created_by', columns: ['created_by_user_id'])]
class ProductionReservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'reservations')]
    #[ORM\JoinColumn(name: 'plan_id', nullable: false, onDelete: 'RESTRICT')]
    private ServiceOrderOperationPlan $plan;

    #[ORM\ManyToOne(targetEntity: Equipment::class)]
    #[ORM\JoinColumn(name: 'equipment_id', nullable: false, onDelete: 'RESTRICT')]
    private Equipment $equipment;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(name: 'segment_number', options: ['unsigned' => true])]
    private int $segmentNumber;

    #[ORM\Column(name: 'starts_at', type: 'utc_datetime_immutable', options: ['comment' => '(DC2Type:utc_datetime_immutable)'])]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(name: 'ends_at', type: 'utc_datetime_immutable', options: ['comment' => '(DC2Type:utc_datetime_immutable)'])]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'utc_datetime_immutable', options: ['comment' => '(DC2Type:utc_datetime_immutable)'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getPlan(): ServiceOrderOperationPlan { return $this->plan; }
    public function setPlan(ServiceOrderOperationPlan $plan): self { $this->plan = $plan; return $this; }
    public function getEquipment(): Equipment { return $this->equipment; }
    public function setEquipment(Equipment $equipment): self { $this->equipment = $equipment; return $this; }
    public function getCreatedBy(): User { return $this->createdBy; }
    public function setCreatedBy(User $actor): self { $this->createdBy = $actor; return $this; }
    public function getSegmentNumber(): int { return $this->segmentNumber; }
    public function setSegmentNumber(int $number): self { if ($number < 1) { throw new \InvalidArgumentException('El segmento debe ser mayor que cero.'); } $this->segmentNumber = $number; return $this; }
    public function getStartsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(\DateTimeImmutable $start): self { $this->startsAt = $start->setTimezone(new \DateTimeZone('UTC')); return $this; }
    public function getEndsAt(): \DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(\DateTimeImmutable $end): self { $this->endsAt = $end->setTimezone(new \DateTimeZone('UTC')); return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function reschedule(\DateTimeImmutable $start, \DateTimeImmutable $end): self
    {
        if ($end <= $start) { throw new \InvalidArgumentException('El fin debe ser posterior al inicio de la reserva.'); }
        $this->setStartsAt($start);
        $this->setEndsAt($end);
        return $this;
    }
    public function deactivate(): self { $this->isActive = false; return $this; }
}
