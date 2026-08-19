<?php

declare(strict_types=1);

namespace App\Entity\Catalog;

use App\Repository\Catalog\CommercialCharacteristicOptionRepository;
use Doctrine\ORM\Mapping as ORM;

/** Opción controlada de una característica SELECT, por ejemplo MATE. */
#[ORM\Entity(repositoryClass: CommercialCharacteristicOptionRepository::class)]
#[ORM\Table(name: 'commercial_characteristic_options')]
#[ORM\UniqueConstraint(name: 'uniq_commercial_characteristic_options_code', columns: ['characteristic_id', 'code'])]
#[ORM\UniqueConstraint(name: 'uniq_commercial_characteristic_options_name', columns: ['characteristic_id', 'name'])]
#[ORM\Index(name: 'idx_commercial_characteristic_options_active_order', columns: ['characteristic_id', 'is_active', 'display_order', 'name'])]
#[ORM\HasLifecycleCallbacks]
class CommercialCharacteristicOption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'options')]
    #[ORM\JoinColumn(name: 'characteristic_id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialCharacteristic $characteristic;

    #[ORM\Column(length: 60)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(name: 'display_order', options: ['unsigned' => true, 'default' => 0])]
    private int $displayOrder = 0;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getCharacteristic(): CommercialCharacteristic { return $this->characteristic; }

    public function setCharacteristic(CommercialCharacteristic $characteristic): self
    {
        if (!$characteristic->getInputType()->supportsOptions()) {
            throw new \DomainException('La característica seleccionada no admite opciones catalogadas.');
        }

        $this->characteristic = $characteristic;

        return $this;
    }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = strtoupper(trim($code)); return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getDisplayOrder(): int { return $this->displayOrder; }

    public function setDisplayOrder(int $displayOrder): self
    {
        if ($displayOrder < 0) { throw new \InvalidArgumentException('El orden de la opción no puede ser negativo.'); }
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
