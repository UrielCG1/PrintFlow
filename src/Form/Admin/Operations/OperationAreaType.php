<?php

declare(strict_types=1);

namespace App\Form\Admin\Operations;

use App\Application\Operations\OperationAreaData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class OperationAreaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Código',
                'help' => 'Identificador interno único. Ejemplo: PREPRESS.',
                'attr' => [
                    'maxlength' => 40,
                    'autocomplete' => 'off',
                    'style' => 'text-transform: uppercase',
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => [
                    'maxlength' => 100,
                    'autocomplete' => 'off',
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
            ->add('displayOrder', IntegerType::class, [
                'label' => 'Orden de visualización',
                'help' => 'Los valores menores aparecen primero. El reordenamiento visual ajusta este valor automáticamente.',
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
            'data_class' => OperationAreaData::class,
        ]);
    }
}