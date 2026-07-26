<?php

namespace App\Entity\Catalog;

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
final class CommercialItem
{
    public const TYPE_PRODUCT = 'PRODUCT';
    public const TYPE_SERVICE = 'SERVICE';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CommercialCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialCategory $category;

    #[ORM\ManyToOne(targetEntity: MeasurementUnit::class)]
    #[ORM\JoinColumn(name: 'measurement_unit_id', nullable: false, onDelete: 'RESTRICT')]
    private MeasurementUnit $measurementUnit;

    #[ORM\Column(length: 80)]
    private string $code;

    #[ORM\Column(length: 20)]
    private string $type = self::TYPE_SERVICE;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'base_price', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $basePrice = '0.00';

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

    public function getId(): ?int { return $this->id; }
    public function getCategory(): CommercialCategory { return $this->category; }
    public function setCategory(CommercialCategory $category): self { $this->category = $category; return $this; }
    public function getMeasurementUnit(): MeasurementUnit { return $this->measurementUnit; }
    public function setMeasurementUnit(MeasurementUnit $measurementUnit): self { $this->measurementUnit = $measurementUnit; return $this; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = strtoupper(trim($code)); return $this; }
    public function getType(): string { return $this->type; }

    public function setType(string $type): self
    {
        $type = strtoupper(trim($type));
        if (!in_array($type, [self::TYPE_PRODUCT, self::TYPE_SERVICE], true)) {
            throw new \InvalidArgumentException('El tipo comercial no es válido.');
        }
        $this->type = $type;
        return $this;
    }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $description = trim((string) $description); $this->description = $description !== '' ? $description : null; return $this; }
    public function getBasePrice(): string { return $this->basePrice; }
    public function setBasePrice(string $basePrice): self { $this->basePrice = self::normalizeDecimal($basePrice, 2); return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }

    private static function normalizeDecimal(string $value, int $scale): string
    {
        $value = str_replace(',', '.', trim($value));
        if (preg_match(sprintf('/^\\d+(?:\\.\\d{1,%d})?$/', $scale), $value) !== 1) {
            throw new \InvalidArgumentException('El precio debe ser un decimal no negativo válido.');
        }
        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        return (ltrim($integer, '0') ?: '0').'.'.str_pad($decimal, $scale, '0');
    }
}