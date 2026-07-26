<?php

namespace App\Entity\Clients;

use App\Repository\Clients\DeliveryZoneRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeliveryZoneRepository::class)]
#[ORM\Table(name: 'delivery_zones')]
#[ORM\UniqueConstraint(name: 'uniq_delivery_zones_name', columns: ['name'])]
#[ORM\Index(name: 'idx_delivery_zones_active_order', columns: ['is_active', 'display_order'])]
#[ORM\HasLifecycleCallbacks]
class DeliveryZone
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'base_delivery_cost', type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $baseDeliveryCost = '0.00';

    #[ORM\Column(name: 'display_order', options: ['default' => 0])]
    private int $displayOrder = 0;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $description = trim((string) $description);
        $this->description = $description !== '' ? $description : null;

        return $this;
    }

    public function getBaseDeliveryCost(): string
    {
        return $this->baseDeliveryCost;
    }

    public function setBaseDeliveryCost(string $baseDeliveryCost): self
    {
        $this->baseDeliveryCost = number_format((float) $baseDeliveryCost, 2, '.', '');

        return $this;
    }

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): self
    {
        $this->displayOrder = $displayOrder;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}