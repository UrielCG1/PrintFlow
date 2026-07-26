<?php

namespace App\Form\Admin\Catalog;

use App\Application\Catalog\MeasurementUnitData;
use Symfony\Component\Form\AbstractType;
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
                'label' => 'Código',
                'help' => 'Identificador interno único. Ejemplo: M2, PZA, ML u HORA.',
                'attr' => [
                    'maxlength' => 30,
                    'autocomplete' => 'off',
                    'style' => 'text-transform: uppercase',
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => [
                    'maxlength' => 80,
                    'autocomplete' => 'off',
                ],
            ])
            ->add('displayOrder', IntegerType::class, [
                'label' => 'Orden de visualización',
                'help' => 'Los valores menores aparecen primero.',
                'attr' => [
                    'min' => 0,
                    'step' => 1,
                    'inputmode' => 'numeric',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MeasurementUnitData::class,
        ]);
    }
}