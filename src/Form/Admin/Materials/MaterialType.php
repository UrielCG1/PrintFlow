<?php

namespace App\Form\Admin\Materials;

use App\Application\Materials\MaterialData;
use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Materials\MaterialCategory;
use App\Entity\Suppliers\Supplier;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class MaterialType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Código',
                'help' => 'Identificador interno único. Ejemplo: LONA-FRONT-13OZ.',
                'attr' => [
                    'autocomplete' => 'off',
                    'maxlength' => 80,
                    'style' => 'text-transform: uppercase',
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => [
                    'autocomplete' => 'off',
                    'maxlength' => 160,
                ],
            ])
            ->add('category', EntityType::class, [
                'class' => MaterialCategory::class,
                'choices' => $options['categories'],
                'choice_label' => static fn (MaterialCategory $category): string => sprintf(
                    '%s — %s%s',
                    $category->getCode(),
                    $category->getName(),
                    $category->isActive() ? '' : ' (inactiva)',
                ),
                'label' => 'Categoría de material',
                'placeholder' => 'Selecciona una categoría',
            ])
            ->add('measurementUnit', EntityType::class, [
                'class' => MeasurementUnit::class,
                'choices' => $options['measurement_units'],
                'choice_label' => static fn (MeasurementUnit $unit): string => sprintf(
                    '%s — %s%s',
                    $unit->getCode(),
                    $unit->getName(),
                    $unit->isActive() ? '' : ' (inactiva)',
                ),
                'label' => 'Unidad de inventario',
                'placeholder' => 'Selecciona una unidad',
            ])
            ->add('primarySupplier', EntityType::class, [
                'class' => Supplier::class,
                'choices' => $options['suppliers'],
                'choice_label' => static fn (Supplier $supplier): string => sprintf(
                    '%s — %s%s',
                    $supplier->getCode(),
                    $supplier->getBusinessName(),
                    $supplier->isActive() ? '' : ' (inactivo)',
                ),
                'label' => 'Proveedor principal',
                'required' => false,
                'placeholder' => 'Sin proveedor principal asignado',
            ])
            ->add('referenceCost', TextType::class, [
                'label' => 'Costo de referencia unitario (MXN)',
                'help' => 'Dato operativo de referencia; no representa una valuación contable.',
                'attr' => [
                    'inputmode' => 'decimal',
                    'maxlength' => 13,
                    'placeholder' => '0.00',
                ],
            ])
            ->add('minimumStock', TextType::class, [
                'label' => 'Stock mínimo',
                'help' => 'Umbral futuro para alertas. No representa existencia actual.',
                'attr' => [
                    'inputmode' => 'decimal',
                    'maxlength' => 13,
                    'placeholder' => '0.000',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Descripción',
                'required' => false,
                'attr' => [
                    'maxlength' => 65535,
                    'rows' => 4,
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Observaciones internas',
                'required' => false,
                'attr' => [
                    'maxlength' => 65535,
                    'rows' => 4,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MaterialData::class,
            'categories' => [],
            'measurement_units' => [],
            'suppliers' => [],
        ]);

        $resolver->setAllowedTypes('categories', 'array');
        $resolver->setAllowedTypes('measurement_units', 'array');
        $resolver->setAllowedTypes('suppliers', 'array');
    }
}