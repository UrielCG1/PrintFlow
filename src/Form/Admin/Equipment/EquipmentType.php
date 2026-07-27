<?php

declare(strict_types=1);

namespace App\Form\Admin\Equipment;

use App\Application\Equipment\EquipmentData;
use App\Entity\Operations\Operation;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class EquipmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('primaryOperation', EntityType::class, [
                'class' => Operation::class,
                'choices' => $options['operations'],
                'choice_label' => static fn (Operation $operation): string => sprintf(
                    '%s — %s (%s)',
                    $operation->getCode(),
                    $operation->getName(),
                    $operation->getOperationArea()->getName(),
                ),
                'label' => 'Operación primaria',
                'help' => 'El área se obtiene de esta operación; por ahora cada equipo se asocia a una sola operación primaria.',
                'placeholder' => 'Selecciona una operación',
            ])
            ->add('code', TextType::class, [
                'label' => 'Código',
                'help' => 'Identificador único y permanente. Ejemplo: EQ-IMP-HP-365.',
                'attr' => [
                    'maxlength' => 40,
                    'autocomplete' => 'off',
                    'style' => 'text-transform: uppercase',
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => [
                    'maxlength' => 160,
                    'autocomplete' => 'off',
                ],
            ])
            ->add('technology', TextType::class, [
                'label' => 'Tecnología o subcategoría',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'autocomplete' => 'off',
                ],
            ])
            ->add('brand', TextType::class, [
                'label' => 'Marca',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'autocomplete' => 'off',
                ],
            ])
            ->add('model', TextType::class, [
                'label' => 'Modelo',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'autocomplete' => 'off',
                ],
            ])
            ->add('serialNumber', TextType::class, [
                'label' => 'Número de serie',
                'required' => false,
                'help' => 'Si se captura, debe ser único para evitar registrar el mismo equipo dos veces.',
                'attr' => [
                    'maxlength' => 100,
                    'autocomplete' => 'off',
                ],
            ])
            ->add('usableWidthCm', TextType::class, [
                'label' => 'Ancho útil (cm)',
                'required' => false,
                'help' => 'Ficha técnica en centímetros; no se usará aún para cálculos automáticos.',
                'attr' => [
                    'maxlength' => 9,
                    'inputmode' => 'decimal',
                    'placeholder' => 'Ejemplo: 160',
                    'autocomplete' => 'off',
                ],
            ])
            ->add('technicalCapacity', TextType::class, [
                'label' => 'Capacidad técnica',
                'required' => false,
                'help' => 'Regístrala como referencia, por ejemplo: 15 m²/h o 50 cm/s. No genera tiempos ni costos.',
                'attr' => [
                    'maxlength' => 120,
                    'autocomplete' => 'off',
                ],
            ])
            ->add('colorConfiguration', TextType::class, [
                'label' => 'Configuración de colores',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'autocomplete' => 'off',
                    'placeholder' => 'Ejemplo: 4 colores',
                ],
            ])
            ->add('observations', TextareaType::class, [
                'label' => 'Observaciones',
                'required' => false,
                'attr' => [
                    'maxlength' => 65535,
                    'rows' => 4,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('operations');
        $resolver->setAllowedTypes('operations', 'array');
        $resolver->setDefaults([
            'data_class' => EquipmentData::class,
        ]);
    }
}