<?php

namespace App\Application\Catalog;

use App\Entity\Catalog\MeasurementUnit;
use App\Enum\Catalog\MeasurementDimensionType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[UniqueEntity(
    fields: ['code'],
    entityClass: MeasurementUnit::class,
    identifierFieldNames: ['id'],
    errorPath: 'code',
    message: 'Ya existe una unidad de medida con este código.',
)]
#[UniqueEntity(
    fields: ['name'],
    entityClass: MeasurementUnit::class,
    identifierFieldNames: ['id'],
    errorPath: 'name',
    message: 'Ya existe una unidad de medida con este nombre.',
)]
final class MeasurementUnitData
{
    public ?int $id = null;

    #[Assert\NotBlank(message: 'Captura el código de la unidad de medida.')]
    #[Assert\Length(max: 30)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9][A-Za-z0-9²_-]*$/u', message: 'El código solo puede contener letras, números, guiones y guiones bajos.')]
    public ?string $code = null;

    #[Assert\NotBlank(message: 'Captura el nombre de la unidad de medida.')]
    #[Assert\Length(max: 80)]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'Captura el símbolo de la unidad de medida.')]
    #[Assert\Length(max: 20)]
    public ?string $symbol = null;

    #[Assert\NotNull(message: 'Selecciona la dimensión de la unidad de medida.')]
    public ?MeasurementDimensionType $dimensionType = MeasurementDimensionType::COUNT;

    public ?MeasurementUnit $baseUnit = null;

    #[Assert\NotBlank(message: 'Captura el factor de conversión.')]
    #[Assert\Regex(
        pattern: '/^\d+(?:\.\d{1,12})?$/',
        message: 'Captura un factor positivo con hasta 12 decimales.',
    )]
    #[Assert\Positive(message: 'El factor de conversión debe ser mayor que cero.')]
    public string $conversionFactor = '1';

    #[Assert\Range(
        min: 0,
        max: 12,
        notInRangeMessage: 'La precisión debe estar entre {{ min }} y {{ max }} decimales.',
    )]
    public int $decimalScale = 6;

    public bool $allowsFraction = true;

    /** Se conserva para persistencia y ordenamiento, pero ya no se captura manualmente en el formulario. */
    #[Assert\Range(min: 0, notInRangeMessage: 'El orden de visualización no puede ser negativo.')]
    public int $displayOrder = 0;

    #[Assert\Callback]
    public function validateConversion(ExecutionContextInterface $context): void
    {
        if ($this->dimensionType === MeasurementDimensionType::COUNT && $this->baseUnit !== null) {
            $context
                ->buildViolation('Las unidades de conteo o presentación no deben declarar una conversión universal.')
                ->atPath('baseUnit')
                ->addViolation();
        }

        if ($this->baseUnit !== null && $this->id !== null && $this->baseUnit->getId() === $this->id) {
            $context
                ->buildViolation('Una unidad no puede utilizarse a sí misma como unidad base.')
                ->atPath('baseUnit')
                ->addViolation();
        }

        if (
            $this->baseUnit !== null
            && $this->dimensionType !== null
            && $this->baseUnit->getDimensionType() !== $this->dimensionType->value
        ) {
            $context
                ->buildViolation('La unidad base debe pertenecer a la misma dimensión seleccionada.')
                ->atPath('baseUnit')
                ->addViolation();
        }

        if ($this->baseUnit !== null && $this->baseUnit->getBaseUnit() !== null) {
            $context
                ->buildViolation('Selecciona una unidad base principal, no una unidad que ya depende de otra.')
                ->atPath('baseUnit')
                ->addViolation();
        }

        if ($this->baseUnit === null && is_numeric($this->conversionFactor) && abs((float) $this->conversionFactor - 1.0) > 0.000000000001) {
            $context
                ->buildViolation('Sin unidad base, el factor de conversión debe ser 1.')
                ->atPath('conversionFactor')
                ->addViolation();
        }
    }
}
