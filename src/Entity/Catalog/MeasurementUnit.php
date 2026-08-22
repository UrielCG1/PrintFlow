<?php

namespace App\Entity\Catalog;

use App\Enum\Catalog\MeasurementDimensionType;
use App\Repository\Catalog\MeasurementUnitRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MeasurementUnitRepository::class)]
#[ORM\Table(name: 'measurement_units')]
#[ORM\UniqueConstraint(name: 'uniq_measurement_units_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_measurement_units_name', columns: ['name'])]
#[ORM\Index(name: 'idx_measurement_units_active_order', columns: ['is_active', 'display_order', 'name'])]
#[ORM\HasLifecycleCallbacks]
/**
 * Unidad de medida universal y su relación con una dimensión física.
 *
 * Campos: id identifica el registro; code y name lo nombran; baseUnit y
 * conversionFactor permiten conversiones compatibles; symbol es la
 * abreviatura; dimensionType evita mezclar dimensiones; decimalScale y
 * allowsFraction controlan precisión; displayOrder ordena el catálogo;
 * isActive controla selección; createdAt y updatedAt conservan auditoría UTC.
 */
class MeasurementUnit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Unidad base de la misma dimensión; NULL en unidades base o presentaciones contextuales. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'base_unit_id', nullable: true, onDelete: 'RESTRICT')]
    private ?self $baseUnit = null;

    #[ORM\Column(length: 30)]
    private string $code;

    #[ORM\Column(length: 80)]
    private string $name;

    /** Abreviatura visible, por ejemplo m, m², kg o pza. */
    #[ORM\Column(length: 20)]
    private string $symbol = '';

    /** Dimensión física que impide conversiones incompatibles. */
    #[ORM\Column(name: 'dimension_type', length: 20)]
    private string $dimensionType = MeasurementDimensionType::COUNT->value;

    /** Factor hacia baseUnit; cuando baseUnit es NULL debe mantenerse en 1. */
    #[ORM\Column(name: 'conversion_factor', type: 'decimal', precision: 24, scale: 12)]
    private string $conversionFactor = '1.000000000000';

    /** Número recomendado de decimales al presentar o redondear cantidades. */
    #[ORM\Column(name: 'decimal_scale', type: 'smallint', options: ['unsigned' => true])]
    private int $decimalScale = 6;

    /** Indica si la unidad acepta fracciones; una pieza normalmente no las acepta. */
    #[ORM\Column(name: 'allows_fraction')]
    private bool $allowsFraction = true;

    #[ORM\Column(name: 'display_order', options: ['unsigned' => true, 'default' => 0])]
    private int $displayOrder = 0;

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
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = strtoupper(trim($code)); return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }

    public function getSymbol(): string { return $this->symbol; }

    public function setSymbol(string $symbol): self
    {
        $symbol = trim($symbol);

        if ($symbol === '') {
            throw new \InvalidArgumentException('El símbolo de la unidad de medida no puede estar vacío.');
        }

        $this->symbol = $symbol;

        return $this;
    }

    public function getDimensionType(): string { return $this->dimensionType; }

    public function getDimension(): MeasurementDimensionType
    {
        return MeasurementDimensionType::from($this->dimensionType);
    }

    public function setDimensionType(MeasurementDimensionType|string $dimensionType): self
    {
        $dimension = $dimensionType instanceof MeasurementDimensionType
            ? $dimensionType
            : MeasurementDimensionType::from(strtoupper(trim($dimensionType)));

        if ($this->baseUnit !== null && $this->baseUnit->getDimensionType() !== $dimension->value) {
            throw new \DomainException('La dimensión debe coincidir con la unidad base seleccionada.');
        }

        $this->dimensionType = $dimension->value;

        return $this;
    }

    public function getBaseUnit(): ?self { return $this->baseUnit; }

    public function setBaseUnit(?self $baseUnit): self
    {
        if ($baseUnit === $this) {
            throw new \DomainException('Una unidad no puede utilizarse a sí misma como unidad base.');
        }

        if ($baseUnit !== null && $baseUnit->getDimensionType() !== $this->dimensionType) {
            throw new \DomainException('La unidad base debe pertenecer a la misma dimensión.');
        }

        $this->baseUnit = $baseUnit;

        return $this;
    }

    public function getConversionFactor(): string { return $this->conversionFactor; }
    public function getConversionFactorAsFloat(): float { return (float) $this->conversionFactor; }

    public function setConversionFactor(string|int|float $conversionFactor): self
    {
        $value = trim((string) $conversionFactor);

        if (!is_numeric($value) || (float) $value <= 0) {
            throw new \InvalidArgumentException('El factor de conversión debe ser mayor que cero.');
        }

        $this->conversionFactor = number_format((float) $value, 12, '.', '');

        return $this;
    }

    public function getDecimalScale(): int { return $this->decimalScale; }

    public function setDecimalScale(int $decimalScale): self
    {
        if ($decimalScale < 0 || $decimalScale > 12) {
            throw new \InvalidArgumentException('La precisión debe estar entre 0 y 12 decimales.');
        }

        $this->decimalScale = $decimalScale;

        return $this;
    }

    public function allowsFraction(): bool { return $this->allowsFraction; }
    public function setAllowsFraction(bool $allowsFraction): self { $this->allowsFraction = $allowsFraction; return $this; }

    public function getDisplayOrder(): int { return $this->displayOrder; }

    public function setDisplayOrder(int $displayOrder): self
    {
        if ($displayOrder < 0) {
            throw new \InvalidArgumentException('El orden de visualización no puede ser negativo.');
        }

        $this->displayOrder = $displayOrder;

        return $this;
    }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
