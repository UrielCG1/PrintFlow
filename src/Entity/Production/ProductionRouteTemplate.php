<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Users\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'production_route_templates')]
#[ORM\UniqueConstraint(name: 'uniq_production_route_templates_code', columns: ['code'])]
#[ORM\Index(name: 'idx_production_route_templates_active_name', columns: ['is_active', 'name'])]
#[ORM\Index(name: 'idx_production_route_templates_created_by', columns: ['created_by_user_id'])]
#[ORM\HasLifecycleCallbacks]
class ProductionRouteTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $code;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ProductionRouteTemplateStep> */
    #[ORM\OneToMany(mappedBy: 'template', targetEntity: ProductionRouteTemplateStep::class, cascade: ['persist'])]
    #[ORM\OrderBy(['sequenceNumber' => 'ASC', 'id' => 'ASC'])]
    private Collection $steps;

    public function __construct()
    {
        $this->steps = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = strtoupper(trim($code)); return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $description = trim((string) $description); $this->description = $description !== '' ? $description : null; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
    public function getCreatedBy(): User { return $this->createdBy; }
    public function setCreatedBy(User $createdBy): self { $this->createdBy = $createdBy; return $this; }
    /** @return Collection<int, ProductionRouteTemplateStep> */
    public function getSteps(): Collection { return $this->steps; }
    public function addStep(ProductionRouteTemplateStep $step): self { if (!$this->steps->contains($step)) { $this->steps->add($step); $step->setTemplate($this); } return $this; }
    public function removeStep(ProductionRouteTemplateStep $step): self { $this->steps->removeElement($step); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
