<?php

namespace App\Entity\Catalog;

use App\Repository\Catalog\MeasurementUnitRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MeasurementUnitRepository::class)]
#[ORM\Table(name: 'measurement_units')]
#[ORM\UniqueConstraint(name: 'uniq_measurement_units_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_measurement_units_name', columns: ['name'])]
#[ORM\Index(name: 'idx_measurement_units_active_order', columns: ['is_active', 'display_order', 'name'])]
#[ORM\HasLifecycleCallbacks]
class MeasurementUnit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private string $code;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(name: 'display_order', options: ['unsigned' => true, 'default' => 0])]
    private int $displayOrder = 0;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', options: ['comment' => '(DC2Type:datetime_immutable)'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', options: ['comment' => '(DC2Type:datetime_immutable)'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = strtoupper(trim($code)); return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getDisplayOrder(): int { return $this->displayOrder; }

    public function setDisplayOrder(int $displayOrder): self
    {
        if ($displayOrder < 0) { throw new \InvalidArgumentException('El orden de visualización no puede ser negativo.'); }
        $this->displayOrder = $displayOrder;
        return $this;
    }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}