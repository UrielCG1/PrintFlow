<?php

declare(strict_types=1);

namespace App\Entity\Discounts;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Brick\Math\BigDecimal;

#[ORM\Entity]
#[ORM\Table(name: 'volume_discount_rules')]
#[ORM\UniqueConstraint(name: 'uniq_volume_rules_profile_minimum', columns: ['costing_profile_id', 'min_volume'])]
#[ORM\HasLifecycleCallbacks]
class VolumeDiscountRule
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(targetEntity: CommercialCostingProfile::class)]
    #[ORM\JoinColumn(name: 'costing_profile_id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialCostingProfile $profile;
    #[ORM\ManyToOne(targetEntity: DiscountType::class)]
    #[ORM\JoinColumn(name: 'discount_type_id', nullable: false, onDelete: 'RESTRICT')]
    private DiscountType $discountType;
    #[ORM\Column(name: 'min_volume', type: Types::DECIMAL, precision: 18, scale: 6)] private string $minVolume;
    #[ORM\Column(name: 'discount_percent', type: Types::DECIMAL, precision: 7, scale: 4)] private string $discountPercent;
    #[ORM\Column(name: 'config_revision', options: ['unsigned' => true, 'default' => 1])] private int $configRevision = 1;
    #[ORM\Column(name: 'is_active', options: ['default' => true])] private bool $isActive = true;
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt;
    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $updatedAt;
    public function __construct()
    { $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); $this->createdAt = $this->updatedAt = $now; }
    public function getId(): ?int { return $this->id; }
    public function getProfile(): CommercialCostingProfile { return $this->profile; }
    public function setProfile(CommercialCostingProfile $v): self { $this->profile = $v; return $this; }
    public function getDiscountType(): DiscountType { return $this->discountType; }
    public function setDiscountType(DiscountType $v): self { $this->discountType = $v; return $this; }
    public function getMinVolume(): string { return $this->minVolume; }
    public function setMinVolume(string $v): self { $v = BigDecimal::of(str_replace(',', '.', trim($v))); if ($v->compareTo('0') <= 0) throw new \InvalidArgumentException('El volumen mínimo debe ser mayor que cero.'); $this->minVolume = $v->toScale(6)->__toString(); ++$this->configRevision; return $this; }
    public function getDiscountPercent(): string { return $this->discountPercent; }
    public function setDiscountPercent(string $v): self { $v = BigDecimal::of(str_replace(',', '.', trim($v))); if ($v->compareTo('0') < 0 || $v->compareTo('100') > 0) throw new \InvalidArgumentException('El porcentaje debe estar entre 0 y 100.'); $this->discountPercent = $v->toScale(4)->__toString(); ++$this->configRevision; return $this; }
    public function getConfigRevision(): int { return $this->configRevision; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): self { $this->isActive = $v; ++$this->configRevision; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    #[ORM\PreUpdate] public function refreshUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
