<?php

declare(strict_types=1);

namespace App\Entity\Discounts;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'discount_types')]
#[ORM\UniqueConstraint(name: 'uniq_discount_types_code', columns: ['code'])]
#[ORM\HasLifecycleCallbacks]
class DiscountType
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 30)] private string $code;
    #[ORM\Column(length: 100)] private string $name;
    #[ORM\Column(type: Types::TEXT, nullable: true)] private ?string $description = null;
    #[ORM\Column(name: 'display_order', options: ['default' => 0])] private int $displayOrder = 0;
    #[ORM\Column(name: 'is_active', options: ['default' => true])] private bool $isActive = true;
    #[ORM\Column(name: 'is_system', options: ['default' => true])] private bool $isSystem = true;
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt;
    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $updatedAt;

    public function __construct(string $code = 'VOLUME', string $name = 'Por volumen')
    {
        $this->code = strtoupper(trim($code)); $this->name = trim($name);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); $this->createdAt = $this->updatedAt = $now;
    }
    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = strtoupper(trim($code)); return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $value): self { $this->description = trim((string) $value) ?: null; return $this; }
    public function getDisplayOrder(): int { return $this->displayOrder; }
    public function setDisplayOrder(int $value): self { $this->displayOrder = max(0, $value); return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $value): self { $this->isActive = $value; return $this; }
    public function isSystem(): bool { return $this->isSystem; }
    public function setIsSystem(bool $value): self { $this->isSystem = $value; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    #[ORM\PreUpdate] public function refreshUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
