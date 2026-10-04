<?php

declare(strict_types=1);

namespace App\Entity\Discounts;

use App\Entity\Catalog\CommercialCategory;
use App\Entity\Catalog\MeasurementUnit;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'commercial_costing_profiles')]
#[ORM\UniqueConstraint(name: 'uniq_costing_profiles_category', columns: ['commercial_category_id'])]
#[ORM\HasLifecycleCallbacks]
class CommercialCostingProfile
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\OneToOne(targetEntity: CommercialCategory::class)]
    #[ORM\JoinColumn(name: 'commercial_category_id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialCategory $commercialCategory;
    #[ORM\ManyToOne(targetEntity: MeasurementUnit::class)]
    #[ORM\JoinColumn(name: 'costing_unit_id', nullable: false, onDelete: 'RESTRICT')]
    private MeasurementUnit $costingUnit;
    #[ORM\Column(name: 'calculation_method', length: 60)] private string $calculationMethod;
    #[ORM\Column(name: 'profile_revision', options: ['unsigned' => true, 'default' => 1])] private int $profileRevision = 1;
    #[ORM\Column(name: 'strategy_version', options: ['unsigned' => true, 'default' => 1])] private int $strategyVersion = 1;
    #[ORM\Column(name: 'parameters_json', type: Types::JSON)] private array $parameters = [];
    #[ORM\Column(name: 'is_active', options: ['default' => true])] private bool $isActive = true;
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt;
    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $updatedAt;
    public function __construct()
    { $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); $this->createdAt = $this->updatedAt = $now; }
    public function getId(): ?int { return $this->id; }
    public function getCommercialCategory(): CommercialCategory { return $this->commercialCategory; }
    public function setCommercialCategory(CommercialCategory $v): self { $this->commercialCategory = $v; return $this; }
    public function getCostingUnit(): MeasurementUnit { return $this->costingUnit; }
    public function setCostingUnit(MeasurementUnit $v): self { $this->costingUnit = $v; return $this; }
    public function getCalculationMethod(): string { return $this->calculationMethod; }
    public function setCalculationMethod(string $v): self { $this->calculationMethod = strtoupper(trim($v)); ++$this->profileRevision; return $this; }
    public function getProfileRevision(): int { return $this->profileRevision; }
    public function getStrategyVersion(): int { return $this->strategyVersion; }
    public function setStrategyVersion(int $v): self { if ($v < 1) throw new \InvalidArgumentException('La versión de estrategia debe ser positiva.'); $this->strategyVersion = $v; return $this; }
    public function getParameters(): array { return $this->parameters; }
    public function setParameters(array $v): self { $this->parameters = $v; ++$this->profileRevision; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): self { $this->isActive = $v; ++$this->profileRevision; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    #[ORM\PreUpdate] public function refreshUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
