<?php

namespace App\Form\Admin\Materials;

use App\Application\Materials\MaterialCategoryData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class MaterialCategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Código',
                'help' => 'Identificador interno único. Ejemplo: SUSTRATOS.',
                'attr' => [
                    'autocomplete' => 'off',
                    'maxlength' => 40,
                    'style' => 'text-transform: uppercase',
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => [
                    'autocomplete' => 'off',
                    'maxlength' => 100,
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Descripción',
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
            'data_class' => MaterialCategoryData::class,
        ]);
    }
}