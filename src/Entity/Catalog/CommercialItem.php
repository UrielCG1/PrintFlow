<?php

namespace App\Entity\Catalog;

use App\Enum\Catalog\CommercialItemType;
use App\Enum\Quotations\QuotationItemSpecificationProfile;
use App\Repository\Catalog\CommercialItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommercialItemRepository::class)]
#[ORM\Table(name: 'commercial_items')]
#[ORM\UniqueConstraint(name: 'uniq_commercial_items_code', columns: ['code'])]
#[ORM\Index(name: 'idx_commercial_items_active_name', columns: ['is_active', 'name'])]
#[ORM\Index(name: 'idx_commercial_items_category_active', columns: ['category_id', 'is_active'])]
#[ORM\Index(name: 'idx_commercial_items_unit_active', columns: ['measurement_unit_id', 'is_active'])]
#[ORM\HasLifecycleCallbacks]
class CommercialItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'category_id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialCategory $category;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'measurement_unit_id', nullable: false, onDelete: 'RESTRICT')]
    private MeasurementUnit $measurementUnit;

    #[ORM\Column(length: 80)]
    private string $code;

    #[ORM\Column(length: 20, enumType: CommercialItemType::class)]
    private CommercialItemType $type;

    #[ORM\Column(name: 'quotation_specification_profile', length: 30, enumType: QuotationItemSpecificationProfile::class, options: ['default' => 'NONE'])]
    private QuotationItemSpecificationProfile $quotationSpecificationProfile = QuotationItemSpecificationProfile::NONE;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'base_price', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $basePrice;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', options: ['comment' => '(DC2Type:datetime_immutable)'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', options: ['comment' => '(DC2Type:datetime_immutable)'])]
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

    public function getCategory(): CommercialCategory
    {
        return $this->category;
    }

    public function setCategory(CommercialCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getMeasurementUnit(): MeasurementUnit
    {
        return $this->measurementUnit;
    }

    public function setMeasurementUnit(MeasurementUnit $measurementUnit): self
    {
        $this->measurementUnit = $measurementUnit;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = strtoupper(trim($code));

        return $this;
    }

    public function getType(): CommercialItemType
    {
        return $this->type;
    }

    public function setType(CommercialItemType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getQuotationSpecificationProfile(): QuotationItemSpecificationProfile
    {
        return $this->quotationSpecificationProfile;
    }

    public function setQuotationSpecificationProfile(QuotationItemSpecificationProfile $profile): self
    {
        $this->quotationSpecificationProfile = $profile;

        return $this;
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

    public function getBasePrice(): string
    {
        return $this->basePrice;
    }

    public function setBasePrice(string $basePrice): self
    {
        $value = trim(str_replace(',', '.', $basePrice));

        if (preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('El precio base no tiene un formato válido.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';

        $this->basePrice = $integer.'.'.str_pad($decimal, 2, '0');

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
