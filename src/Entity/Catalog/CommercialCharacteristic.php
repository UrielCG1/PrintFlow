<?php

declare(strict_types=1);

namespace App\Entity\Catalog;

use App\Enum\Catalog\CommercialCharacteristicInputType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Característica reutilizable para configurar productos comerciales.
 *
 * Ejemplos: acabado, adhesivo, corte o ancho terminado. La característica
 * no pertenece al inventario y por sí misma no modifica precios.
 */
#[ORM\Entity]
#[ORM\Table(name: 'commercial_characteristics')]
#[ORM\UniqueConstraint(name: 'uniq_commercial_characteristics_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_commercial_characteristics_name', columns: ['name'])]
#[ORM\Index(name: 'idx_commercial_characteristics_active_order', columns: ['is_active', 'display_order', 'name'])]
#[ORM\HasLifecycleCallbacks]
final class CommercialCharacteristic
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(name: 'input_type', length: 20, enumType: CommercialCharacteristicInputType::class)]
    private CommercialCharacteristicInputType $inputType;

    #[ORM\Column(name: 'unit_label', length: 20, nullable: true)]
    private ?string $unitLabel = null;

    #[ORM\Column(name: 'display_order', options: ['unsigned' => true, 'default' => 0])]
    private int $displayOrder = 0;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, CommercialCharacteristicOption> */
    #[ORM\OneToMany(mappedBy: 'characteristic', targetEntity: CommercialCharacteristicOption::class, cascade: ['persist'])]
    #[ORM\OrderBy(['displayOrder' => 'ASC', 'name' => 'ASC'])]
    private Collection $options;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->options = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = strtoupper(trim($code)); return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getInputType(): CommercialCharacteristicInputType { return $this->inputType; }
    public function setInputType(CommercialCharacteristicInputType $inputType): self { $this->inputType = $inputType; return $this; }
    public function getUnitLabel(): ?string { return $this->unitLabel; }
    public function setUnitLabel(?string $unitLabel): self { $unitLabel = trim((string) $unitLabel); $this->unitLabel = $unitLabel !== '' ? $unitLabel : null; return $this; }
    public function getDisplayOrder(): int { return $this->displayOrder; }

    public function setDisplayOrder(int $displayOrder): self
    {
        if ($displayOrder < 0) { throw new \InvalidArgumentException('El orden de la característica no puede ser negativo.'); }
        $this->displayOrder = $displayOrder;

        return $this;
    }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, CommercialCharacteristicOption> */
    public function getOptions(): Collection { return $this->options; }

    public function addOption(CommercialCharacteristicOption $option): self
    {
        if (!$this->inputType->supportsOptions()) {
            throw new \DomainException('Solo una característica de lista puede tener opciones catalogadas.');
        }

        if (!$this->options->contains($option)) {
            $this->options->add($option);
            $option->setCharacteristic($this);
        }

        return $this;
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
