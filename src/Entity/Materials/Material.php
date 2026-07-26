<?php

namespace App\Entity\Materials;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Suppliers\Supplier;
use App\Repository\Materials\MaterialRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MaterialRepository::class)]
#[ORM\Table(name: 'materials')]
#[ORM\UniqueConstraint(name: 'uniq_materials_code', columns: ['code'])]
#[ORM\Index(name: 'idx_materials_active_name', columns: ['is_active', 'name'])]
#[ORM\Index(name: 'idx_materials_category_active', columns: ['category_id', 'is_active'])]
#[ORM\Index(name: 'idx_materials_unit_active', columns: ['measurement_unit_id', 'is_active'])]
#[ORM\Index(name: 'idx_materials_supplier_active', columns: ['primary_supplier_id', 'is_active'])]
#[ORM\HasLifecycleCallbacks]
final class Material
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'category_id', nullable: false, onDelete: 'RESTRICT')]
    private MaterialCategory $category;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'measurement_unit_id', nullable: false, onDelete: 'RESTRICT')]
    private MeasurementUnit $measurementUnit;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'primary_supplier_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Supplier $primarySupplier = null;

    #[ORM\Column(length: 80)]
    private string $code;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'reference_cost', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $referenceCost = '0.00';

    #[ORM\Column(name: 'minimum_stock', type: Types::DECIMAL, precision: 12, scale: 3)]
    private string $minimumStock = '0.000';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(
        name: 'created_at',
        type: 'datetime_immutable',
        options: ['comment' => '(DC2Type:datetime_immutable)'],
    )]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(
        name: 'updated_at',
        type: 'datetime_immutable',
        options: ['comment' => '(DC2Type:datetime_immutable)'],
    )]
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

    public function getCategory(): MaterialCategory
    {
        return $this->category;
    }

    public function setCategory(MaterialCategory $category): self
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

    public function getPrimarySupplier(): ?Supplier
    {
        return $this->primarySupplier;
    }

    public function setPrimarySupplier(?Supplier $primarySupplier): self
    {
        $this->primarySupplier = $primarySupplier;

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

    public function getReferenceCost(): string
    {
        return $this->referenceCost;
    }

    public function setReferenceCost(string $referenceCost): self
    {
        $this->referenceCost = $this->normalizeDecimal(
            $referenceCost,
            2,
            'El costo de referencia no tiene un formato válido.',
        );

        return $this;
    }

    public function getMinimumStock(): string
    {
        return $this->minimumStock;
    }

    public function setMinimumStock(string $minimumStock): self
    {
        $this->minimumStock = $this->normalizeDecimal(
            $minimumStock,
            3,
            'El stock mínimo no tiene un formato válido.',
        );

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $notes = trim((string) $notes);
        $this->notes = $notes !== '' ? $notes : null;

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
        $this->updatedAt = new \DateTimeImmutable(
            'now',
            new \DateTimeZone('UTC'),
        );
    }

    private function normalizeDecimal(
        string $value,
        int $scale,
        string $errorMessage,
    ): string {
        $value = trim(str_replace(',', '.', $value));

        $integerDigits = 12 - $scale;

        $pattern = sprintf(
            '/^(?:0|[1-9]\d{0,%d})(?:\.\d{1,%d})?$/D',
            $integerDigits - 1,
            $scale,
        );

        if (preg_match($pattern, $value) !== 1) {
            throw new \InvalidArgumentException($errorMessage);
        }

        [$integer, $decimal] = array_pad(
            explode('.', $value, 2),
            2,
            '',
        );

        $integer = ltrim($integer, '0') ?: '0';

        return $integer.'.'.str_pad($decimal, $scale, '0');
    }
}