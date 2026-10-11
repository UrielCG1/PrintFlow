<?php

declare(strict_types=1);

namespace App\Form\Admin\Equipment;

use App\Application\Equipment\EquipmentData;
use App\Entity\Operations\Operation;
use App\Enum\Equipment\ProductionCapacityUnit;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
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
                'help' => 'Referencia histórica. No se convierte en rendimiento ni se usa para estimar.',
                'attr' => [
                    'maxlength' => 120,
                    'autocomplete' => 'off',
                ],
            ])
            ->add('capacityUnit', EnumType::class, [
                'class' => ProductionCapacityUnit::class,
                'label' => 'Unidad de rendimiento estructurado',
                'required' => false,
                'placeholder' => 'Sin validar / no estimable',
                'choice_label' => static fn (ProductionCapacityUnit $unit): string => $unit->label(),
                'help' => 'Selecciona solo después de medir la máquina. Para corte se captura trayectoria en cm, no piezas.',
            ])
            ->add('capacityRate', TextType::class, [
                'label' => 'Rendimiento medido',
                'required' => false,
                'attr' => ['inputmode' => 'decimal', 'placeholder' => 'Ejemplo: 15'],
            ])
            ->add('setupMinutes', IntegerType::class, [
                'label' => 'Preparación (minutos por trabajo)',
                'required' => false,
                'help' => 'Tiempo de preparación agregado una vez por operación.',
            ])
            ->add('cleanupMinutes', IntegerType::class, [
                'label' => 'Limpieza / cierre (minutos por trabajo)',
                'required' => false,
            ])
            ->add('efficiencyPercent', TextType::class, [
                'label' => 'Eficiencia observada (%)',
                'required' => false,
                'attr' => ['inputmode' => 'decimal', 'placeholder' => '100'],
            ])
            ->add('capacityValidFrom', TextType::class, [
                'label' => 'Rendimiento válido desde',
                'required' => false,
                'attr' => ['type' => 'date'],
            ])
            ->add('capacityValidUntil', TextType::class, [
                'label' => 'Rendimiento válido hasta',
                'required' => false,
                'attr' => ['type' => 'date'],
            ])
            ->add('capacityValidated', CheckboxType::class, [
                'label' => 'Confirmo que estos datos fueron medidos y validados en el taller',
                'required' => false,
                'help' => 'Al guardar se registra mi usuario y la fecha. Si no se confirma, el equipo seguirá sin estimación automática.',
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
