<?php

declare(strict_types=1);

namespace App\Application\Equipment;

use App\Entity\Equipment\Equipment;
use App\Entity\Operations\Operation;
use App\Enum\Equipment\ProductionCapacityUnit;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[UniqueEntity(
    fields: ['code'],
    entityClass: Equipment::class,
    identifierFieldNames: ['id'],
    errorPath: 'code',
    message: 'Ya existe un equipo con este código.',
)]
final class EquipmentData
{
    public ?int $id = null;

    #[Assert\NotNull(message: 'Selecciona la operación primaria del equipo.')]
    public ?Operation $primaryOperation = null;

    #[Assert\NotBlank(message: 'Captura el código del equipo.')]
    #[Assert\Length(max: 40)]
    #[Assert\Regex(
        pattern: '/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
        message: 'El código solo puede contener letras, números, guiones y guiones bajos.',
    )]
    public ?string $code = null;

    #[Assert\NotBlank(message: 'Captura el nombre del equipo.')]
    #[Assert\Length(max: 160)]
    public ?string $name = null;

    #[Assert\Length(max: 100)]
    public ?string $technology = null;

    #[Assert\Length(max: 100)]
    public ?string $brand = null;

    #[Assert\Length(max: 100)]
    public ?string $model = null;

    #[Assert\Length(max: 100)]
    public ?string $serialNumber = null;

    #[Assert\Length(max: 9)]
    #[Assert\Regex(
        pattern: '/^(?:[1-9]\d{0,5})(?:[.,]\d{1,2})?$/',
        message: 'Captura un ancho útil positivo con máximo dos decimales.',
    )]
    public ?string $usableWidthCm = null;

    #[Assert\Length(max: 120)]
    public ?string $technicalCapacity = null;

    public ?ProductionCapacityUnit $capacityUnit = null;

    #[Assert\Regex(pattern: '/^(?:0*[1-9]\d{0,7}|0?\.[0-9]*[1-9][0-9]*)(?:[.,]\d{1,4})?$/', message: 'Captura un rendimiento positivo con máximo cuatro decimales.')]
    public ?string $capacityRate = null;

    #[Assert\Range(min: 0, max: 1440)]
    public ?int $setupMinutes = 0;

    #[Assert\Range(min: 0, max: 1440)]
    public ?int $cleanupMinutes = 0;

    #[Assert\Regex(pattern: '/^(?:100(?:[.,]0{1,2})?|(?:[1-9]\d?)(?:[.,]\d{1,2})?)$/', message: 'La eficiencia debe estar entre 1 y 100%.')]
    public string $efficiencyPercent = '100';

    #[Assert\Regex(pattern: '/^\d{4}-\d{2}-\d{2}$/', message: 'Usa una fecha válida (AAAA-MM-DD).')]
    public ?string $capacityValidFrom = null;

    #[Assert\Regex(pattern: '/^\d{4}-\d{2}-\d{2}$/', message: 'Usa una fecha válida (AAAA-MM-DD).')]
    public ?string $capacityValidUntil = null;

    public bool $capacityValidated = false;

    #[Assert\Length(max: 100)]
    public ?string $colorConfiguration = null;

    #[Assert\Length(max: 65535)]
    public ?string $observations = null;
}
