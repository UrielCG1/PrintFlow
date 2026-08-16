<?php

namespace App\Entity\Catalog;

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
 * conversionFactor permiten conversiones universales compatibles; symbol es
 * la abreviatura; dimensionType evita mezclar dimensiones; decimalScale y
 * allowsFraction controlan precisión; displayOrder ordena el catálogo;
 * isActive controla selección; createdAt y updatedAt conservan auditoría UTC.
 */
class MeasurementUnit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Unidad base de la misma dimensión; NULL en presentaciones contextuales como rollo o caja. */
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

    /** Dimensión física que impide conversiones incompatibles: COUNT, LENGTH, AREA, VOLUME, MASS o TIME. */
    #[ORM\Column(name: 'dimension_type', length: 20)]
    private string $dimensionType = 'COUNT';

    /** Factor universal hacia la unidad base de su dimensión. */
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
    public function getDisplayOrder(): int { return $this->displayOrder; }

    public function setDisplayOrder(int $displayOrder): self
    {
        if ($displayOrder < 0) { throw new \InvalidArgumentException('El orden de visualización no puede ser negativo.'); }
        $this->displayOrder = $displayOrder;
        return $this;
    }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
