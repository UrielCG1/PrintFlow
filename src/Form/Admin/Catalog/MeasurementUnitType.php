<?php

namespace App\Form\Admin\Catalog;

use App\Application\Catalog\MeasurementUnitData;
use App\Entity\Catalog\MeasurementUnit;
use App\Enum\Catalog\MeasurementDimensionType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class MeasurementUnitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Código técnico',
                'help' => $options['lock_code']
                    ? 'Código protegido porque forma parte de una regla técnica del cotizador.'
                    : 'Identificador interno único. Evita cambiarlo después de comenzar a utilizar la unidad.',
                'disabled' => $options['lock_code'],
                'attr' => [
                    'maxlength' => 30,
                    'autocomplete' => 'off',
                    'style' => 'text-transform: uppercase',
                    'placeholder' => 'Ej. M2, PZA, HORA',
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => [
                    'maxlength' => 80,
                    'autocomplete' => 'off',
                    'placeholder' => 'Ej. Metro cuadrado',
                ],
            ])
            ->add('symbol', TextType::class, [
                'label' => 'Símbolo',
                'help' => 'Abreviatura visible junto a cantidades y precios.',
                'attr' => [
                    'maxlength' => 20,
                    'autocomplete' => 'off',
                    'placeholder' => 'Ej. m²',
                ],
            ])
            ->add('dimensionType', ChoiceType::class, [
                'label' => 'Dimensión',
                'help' => 'Evita relacionar conversiones incompatibles, por ejemplo longitud con tiempo.',
                'choices' => MeasurementDimensionType::orderedCases(),
                'choice_label' => static fn (MeasurementDimensionType $dimension): string => $dimension->label(),
                'choice_value' => static fn (?MeasurementDimensionType $dimension): string => $dimension?->value ?? '',
                'placeholder' => 'Selecciona una dimensión',
                'disabled' => $options['lock_conversion'],
                'attr' => [
                    'data-ui--measurement-unit-form-target' => 'dimension',
                    'data-action' => 'change->ui--measurement-unit-form#syncDimension',
                ],
            ])
            ->add('baseUnit', ChoiceType::class, [
                'label' => 'Unidad base',
                'help' => 'Opcional. Selecciona una unidad principal de la misma dimensión si esta unidad es convertible.',
                'required' => false,
                'disabled' => $options['lock_conversion'],
                'placeholder' => 'Sin unidad base',
                'choices' => $options['base_units'],
                'choice_value' => 'id',
                'choice_label' => static function (MeasurementUnit $unit): string {
                    $symbol = trim($unit->getSymbol());

                    return $symbol !== ''
                        ? sprintf('%s (%s)', $unit->getName(), $symbol)
                        : $unit->getName();
                },
                'group_by' => static fn (MeasurementUnit $unit): string => $unit->getDimension()->label(),
                'choice_attr' => static fn (MeasurementUnit $unit): array => [
                    'data-dimension' => $unit->getDimensionType(),
                ],
                'attr' => [
                    'data-ui--measurement-unit-form-target' => 'baseUnit',
                    'data-action' => 'change->ui--measurement-unit-form#syncConversion',
                ],
            ])
            ->add('conversionFactor', TextType::class, [
                'label' => 'Factor hacia la unidad base',
                'help' => $options['lock_conversion']
                    ? 'Conversión protegida para esta unidad técnica.'
                    : 'Ejemplo: si 1 cm = 0.01 m, captura 0.01. Sin unidad base debe ser 1.',
                'disabled' => $options['lock_conversion'],
                'attr' => [
                    'autocomplete' => 'off',
                    'inputmode' => 'decimal',
                    'placeholder' => '1',
                    'data-ui--measurement-unit-form-target' => 'factor',
                ],
            ])
            ->add('allowsFraction', CheckboxType::class, [
                'label' => 'Permitir cantidades fraccionarias',
                'help' => 'Desactívalo para unidades que normalmente se venden en enteros, como pieza o caja.',
                'required' => false,
                'attr' => [
                    'data-ui--measurement-unit-form-target' => 'allowsFraction',
                    'data-action' => 'change->ui--measurement-unit-form#syncPrecision',
                ],
            ])
            ->add('decimalScale', IntegerType::class, [
                'label' => 'Precisión de captura',
                'help' => 'Número máximo recomendado de decimales. Si no admite fracciones se guardará en 0.',
                'attr' => [
                    'min' => 0,
                    'max' => 12,
                    'step' => 1,
                    'inputmode' => 'numeric',
                    'data-ui--measurement-unit-form-target' => 'decimalScale',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MeasurementUnitData::class,
            'base_units' => [],
            'lock_code' => false,
            'lock_conversion' => false,
        ]);

        $resolver->setAllowedTypes('base_units', 'array');
        $resolver->setAllowedTypes('lock_code', 'bool');
        $resolver->setAllowedTypes('lock_conversion', 'bool');
    }
}
