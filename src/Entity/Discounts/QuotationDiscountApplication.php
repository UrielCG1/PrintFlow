<?php

declare(strict_types=1);

namespace App\Entity\Discounts;

use App\Entity\Catalog\CommercialCategory;
use App\Entity\Clients\ClientClass;
use App\Entity\Quotations\Quotation;
use App\Entity\Users\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'quotation_discount_applications')]
#[ORM\UniqueConstraint(name: 'uniq_quotation_discount_scope', columns: ['quotation_id', 'pricing_calculation_version', 'discount_type_id', 'scope_key'])]
class QuotationDiscountApplication
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(targetEntity: Quotation::class)] #[ORM\JoinColumn(name: 'quotation_id', nullable: false, onDelete: 'RESTRICT')] private Quotation $quotation;
    #[ORM\ManyToOne(targetEntity: DiscountType::class)] #[ORM\JoinColumn(name: 'discount_type_id', nullable: false, onDelete: 'RESTRICT')] private DiscountType $discountType;
    #[ORM\ManyToOne(targetEntity: CommercialCategory::class)] #[ORM\JoinColumn(name: 'commercial_category_id', nullable: true, onDelete: 'RESTRICT')] private ?CommercialCategory $commercialCategory = null;
    #[ORM\ManyToOne(targetEntity: VolumeDiscountRule::class)] #[ORM\JoinColumn(name: 'source_volume_rule_id', nullable: true, onDelete: 'RESTRICT')] private ?VolumeDiscountRule $sourceVolumeRule = null;
    #[ORM\ManyToOne(targetEntity: ClientClass::class)] #[ORM\JoinColumn(name: 'source_client_class_id', nullable: true, onDelete: 'RESTRICT')] private ?ClientClass $sourceClientClass = null;
    #[ORM\ManyToOne(targetEntity: User::class)] #[ORM\JoinColumn(name: 'created_by_user_id', nullable: true, onDelete: 'SET NULL')] private ?User $createdBy = null;
    #[ORM\Column(name: 'pricing_calculation_version', options: ['unsigned' => true])] private int $pricingCalculationVersion = 1;
    #[ORM\Column(length: 20)] private string $scope = 'QUOTATION';
    #[ORM\Column(name: 'scope_key', length: 80)] private string $scopeKey = 'QUOTE';
    #[ORM\Column(type: Types::DECIMAL, precision: 7, scale: 4)] private string $percentage = '0.0000';
    #[ORM\Column(name: 'base_amount', type: Types::DECIMAL, precision: 14, scale: 2)] private string $baseAmount = '0.00';
    #[ORM\Column(name: 'discount_amount', type: Types::DECIMAL, precision: 14, scale: 2)] private string $discountAmount = '0.00';
    #[ORM\Column(type: Types::TEXT, nullable: true)] private ?string $reason = null;
    #[ORM\Column(name: 'context_snapshot', type: Types::JSON)] private array $contextSnapshot = [];
    #[ORM\Column(name: 'display_order', options: ['default' => 0])] private int $displayOrder = 0;
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt;

    public function __construct(Quotation $quotation, DiscountType $discountType)
    { $this->quotation = $quotation; $this->discountType = $discountType; $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
    public function getId(): ?int { return $this->id; }
    public function getQuotation(): Quotation { return $this->quotation; }
    public function getDiscountType(): DiscountType { return $this->discountType; }
    public function getCommercialCategory(): ?CommercialCategory { return $this->commercialCategory; }
    public function setCommercialCategory(?CommercialCategory $v): self { $this->commercialCategory = $v; return $this; }
    public function getSourceVolumeRule(): ?VolumeDiscountRule { return $this->sourceVolumeRule; }
    public function setSourceVolumeRule(?VolumeDiscountRule $v): self { $this->sourceVolumeRule = $v; return $this; }
    public function getSourceClientClass(): ?ClientClass { return $this->sourceClientClass; }
    public function setSourceClientClass(?ClientClass $v): self { $this->sourceClientClass = $v; return $this; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $v): self { $this->createdBy = $v; return $this; }
    public function getPricingCalculationVersion(): int { return $this->pricingCalculationVersion; }
    public function setPricingCalculationVersion(int $v): self { $this->pricingCalculationVersion = $v; return $this; }
    public function getScope(): string { return $this->scope; }
    public function setScope(string $v): self { $this->scope = strtoupper(trim($v)); return $this; }
    public function getScopeKey(): string { return $this->scopeKey; }
    public function setScopeKey(string $v): self { $this->scopeKey = trim($v); return $this; }
    public function getPercentage(): string { return $this->percentage; }
    public function setPercentage(string $v): self { $this->percentage = \Brick\Math\BigDecimal::of($v)->toScale(4)->__toString(); return $this; }
    public function getBaseAmount(): string { return $this->baseAmount; }
    public function setBaseAmount(string $v): self { $this->baseAmount = \App\Entity\Quotations\Quotation::normalizeAmount($v, 'La base del descuento'); return $this; }
    public function getDiscountAmount(): string { return $this->discountAmount; }
    public function setDiscountAmount(string $v): self { $this->discountAmount = \App\Entity\Quotations\Quotation::normalizeAmount($v, 'El importe del descuento'); return $this; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $v): self { $this->reason = trim((string) $v) ?: null; return $this; }
    public function getContextSnapshot(): array { return $this->contextSnapshot; }
    public function setContextSnapshot(array $v): self { $this->contextSnapshot = $v; return $this; }
    public function getDisplayOrder(): int { return $this->displayOrder; }
    public function setDisplayOrder(int $v): self { $this->displayOrder = max(0, $v); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
