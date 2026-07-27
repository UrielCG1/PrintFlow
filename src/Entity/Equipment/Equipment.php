<?php

declare(strict_types=1);

namespace App\Entity\Equipment;

use App\Entity\Operations\Operation;
use App\Enum\Equipment\EquipmentStatus;
use App\Repository\Equipment\EquipmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EquipmentRepository::class)]
#[ORM\Table(name: 'equipment')]
#[ORM\UniqueConstraint(name: 'uniq_equipment_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_equipment_serial_number', columns: ['serial_number'])]
#[ORM\Index(name: 'idx_equipment_operation_status', columns: ['primary_operation_id', 'status'])]
#[ORM\Index(name: 'idx_equipment_status_name', columns: ['status', 'name'])]
#[ORM\HasLifecycleCallbacks]
class Equipment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Operation::class)]
    #[ORM\JoinColumn(name: 'primary_operation_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Operation $primaryOperation;

    #[ORM\Column(length: 40)]
    private string $code;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $technology = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $brand = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(name: 'serial_number', length: 100, nullable: true)]
    private ?string $serialNumber = null;

    #[ORM\Column(name: 'usable_width_cm', type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $usableWidthCm = null;

    #[ORM\Column(name: 'technical_capacity', length: 120, nullable: true)]
    private ?string $technicalCapacity = null;

    #[ORM\Column(name: 'color_configuration', length: 100, nullable: true)]
    private ?string $colorConfiguration = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observations = null;

    #[ORM\Column(length: 20, enumType: EquipmentStatus::class, options: ['default' => 'available'])]
    private EquipmentStatus $status = EquipmentStatus::AVAILABLE;

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

    public function getPrimaryOperation(): Operation
    {
        return $this->primaryOperation;
    }

    public function setPrimaryOperation(Operation $primaryOperation): self
    {
        $this->primaryOperation = $primaryOperation;

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

    public function getTechnology(): ?string
    {
        return $this->technology;
    }

    public function setTechnology(?string $technology): self
    {
        $this->technology = $this->normalizeNullableText($technology);

        return $this;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): self
    {
        $this->brand = $this->normalizeNullableText($brand);

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): self
    {
        $this->model = $this->normalizeNullableText($model);

        return $this;
    }

    public function getSerialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function setSerialNumber(?string $serialNumber): self
    {
        $this->serialNumber = $this->normalizeNullableText($serialNumber);

        return $this;
    }

    public function getUsableWidthCm(): ?string
    {
        return $this->usableWidthCm;
    }

    public function setUsableWidthCm(?string $usableWidthCm): self
    {
        $value = trim(str_replace(',', '.', (string) $usableWidthCm));
        if ($value === '') {
            $this->usableWidthCm = null;

            return $this;
        }

        if (preg_match('/^(?:[1-9]\d{0,5})(?:\.\d{1,2})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('El ancho útil debe ser un valor positivo con máximo dos decimales.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        $this->usableWidthCm = $integer.'.'.str_pad($decimal, 2, '0');

        return $this;
    }

    public function getTechnicalCapacity(): ?string
    {
        return $this->technicalCapacity;
    }

    public function setTechnicalCapacity(?string $technicalCapacity): self
    {
        $this->technicalCapacity = $this->normalizeNullableText($technicalCapacity);

        return $this;
    }

    public function getColorConfiguration(): ?string
    {
        return $this->colorConfiguration;
    }

    public function setColorConfiguration(?string $colorConfiguration): self
    {
        $this->colorConfiguration = $this->normalizeNullableText($colorConfiguration);

        return $this;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function setObservations(?string $observations): self
    {
        $this->observations = $this->normalizeNullableText($observations);

        return $this;
    }

    public function getStatus(): EquipmentStatus
    {
        return $this->status;
    }

    public function setStatus(EquipmentStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isSelectableForFutureExecution(): bool
    {
        return $this->status->isSelectableForFutureExecution();
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

    private function normalizeNullableText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}