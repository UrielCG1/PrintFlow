<?php

declare(strict_types=1);

namespace App\Entity\Catalog;

use App\Repository\Catalog\CommercialItemCharacteristicRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Configura qué características se solicitan para un producto comercial. */
#[ORM\Entity(repositoryClass: CommercialItemCharacteristicRepository::class)]
#[ORM\Table(name: 'commercial_item_characteristics')]
#[ORM\UniqueConstraint(name: 'uniq_commercial_item_characteristics', columns: ['commercial_item_id', 'characteristic_id'])]
#[ORM\Index(name: 'idx_commercial_item_characteristics_order', columns: ['commercial_item_id', 'display_order'])]
#[ORM\HasLifecycleCallbacks]
class CommercialItemCharacteristic
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'commercial_item_id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialItem $commercialItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'characteristic_id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialCharacteristic $characteristic;

    #[ORM\Column(name: 'is_required', options: ['default' => false])]
    private bool $isRequired = false;

    #[ORM\Column(name: 'display_order', options: ['unsigned' => true, 'default' => 0])]
    private int $displayOrder = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, CommercialItemCharacteristicOption> */
    #[ORM\OneToMany(mappedBy: 'commercialItemCharacteristic', targetEntity: CommercialItemCharacteristicOption::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['displayOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $allowedOptions;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->allowedOptions = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getCommercialItem(): CommercialItem { return $this->commercialItem; }
    public function setCommercialItem(CommercialItem $commercialItem): self { $this->commercialItem = $commercialItem; return $this; }
    public function getCharacteristic(): CommercialCharacteristic { return $this->characteristic; }
    public function setCharacteristic(CommercialCharacteristic $characteristic): self { $this->characteristic = $characteristic; return $this; }
    public function isRequired(): bool { return $this->isRequired; }
    public function setIsRequired(bool $isRequired): self { $this->isRequired = $isRequired; return $this; }
    public function getDisplayOrder(): int { return $this->displayOrder; }

    public function setDisplayOrder(int $displayOrder): self
    {
        if ($displayOrder < 0) { throw new \InvalidArgumentException('El orden de la característica del producto no puede ser negativo.'); }
        $this->displayOrder = $displayOrder;

        return $this;
    }

    /** @return Collection<int, CommercialItemCharacteristicOption> */
    public function getAllowedOptions(): Collection { return $this->allowedOptions; }

    public function addAllowedOption(CommercialItemCharacteristicOption $allowedOption): self
    {
        if (!$this->characteristic->getInputType()->supportsOptions()) {
            throw new \DomainException('Solo las características de lista pueden restringir opciones por producto.');
        }

        if (!$this->allowedOptions->contains($allowedOption)) {
            $this->allowedOptions->add($allowedOption);
            $allowedOption->setCommercialItemCharacteristic($this);
        }

        return $this;
    }

    public function removeAllowedOption(CommercialItemCharacteristicOption $allowedOption): self
    {
        if ($this->allowedOptions->removeElement($allowedOption)) {
            // orphanRemoval elimina la relación y su fila hija al sincronizar Doctrine.
        }

        return $this;
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
