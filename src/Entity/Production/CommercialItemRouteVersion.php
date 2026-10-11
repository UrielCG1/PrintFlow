<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Catalog\CommercialItem;
use App\Entity\Users\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'commercial_item_route_versions')]
#[ORM\UniqueConstraint(name: 'uniq_commercial_item_route_version_number', columns: ['commercial_item_id', 'version_number'])]
#[ORM\Index(name: 'idx_commercial_item_route_version_source_template', columns: ['source_template_id'])]
#[ORM\Index(name: 'idx_commercial_item_route_version_published_by', columns: ['published_by_user_id'])]
class CommercialItemRouteVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'commercial_item_id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialItem $commercialItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'source_template_id', nullable: true, onDelete: 'RESTRICT')]
    private ?ProductionRouteTemplate $sourceTemplate = null;

    #[ORM\Column(name: 'version_number', options: ['unsigned' => true])]
    private int $versionNumber;

    #[ORM\Column(name: 'name_snapshot', length: 120)]
    private string $nameSnapshot;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'definition_snapshot', type: Types::JSON)]
    private array $definitionSnapshot = [];

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'published_by_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $publishedBy;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $publishedAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, CommercialItemRouteStep> */
    #[ORM\OneToMany(mappedBy: 'routeVersion', targetEntity: CommercialItemRouteStep::class, cascade: ['persist'])]
    #[ORM\OrderBy(['sequenceNumber' => 'ASC', 'id' => 'ASC'])]
    private Collection $steps;

    public function __construct()
    {
        $this->steps = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->publishedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCommercialItem(): CommercialItem { return $this->commercialItem; }
    public function setCommercialItem(CommercialItem $item): self { $this->commercialItem = $item; return $this; }
    public function getSourceTemplate(): ?ProductionRouteTemplate { return $this->sourceTemplate; }
    public function setSourceTemplate(?ProductionRouteTemplate $template): self { $this->sourceTemplate = $template; return $this; }
    public function getVersionNumber(): int { return $this->versionNumber; }
    public function setVersionNumber(int $versionNumber): self { if ($versionNumber < 1) { throw new \InvalidArgumentException('La versión debe ser mayor que cero.'); } $this->versionNumber = $versionNumber; return $this; }
    public function getNameSnapshot(): string { return $this->nameSnapshot; }
    public function setNameSnapshot(string $name): self { $this->nameSnapshot = trim($name); return $this; }
    /** @return array<string, mixed> */
    public function getDefinitionSnapshot(): array { return $this->definitionSnapshot; }
    /** @param array<string, mixed> $snapshot */
    public function setDefinitionSnapshot(array $snapshot): self { $this->definitionSnapshot = $snapshot; return $this; }
    public function getPublishedBy(): User { return $this->publishedBy; }
    public function setPublishedBy(User $user): self { $this->publishedBy = $user; return $this; }
    public function getPublishedAt(): \DateTimeImmutable { return $this->publishedAt; }
    /** @return Collection<int, CommercialItemRouteStep> */
    public function getSteps(): Collection { return $this->steps; }
    public function addStep(CommercialItemRouteStep $step): self { if (!$this->steps->contains($step)) { $this->steps->add($step); $step->setRouteVersion($this); } return $this; }
}
