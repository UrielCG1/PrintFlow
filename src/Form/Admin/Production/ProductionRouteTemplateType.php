<?php

declare(strict_types=1);

namespace App\Form\Admin\Production;

use App\Application\Production\ProductionRouteTemplateData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProductionRouteTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Código de ruta',
                'attr' => ['maxlength' => 40, 'style' => 'text-transform: uppercase'],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre de la ruta',
                'attr' => ['maxlength' => 120],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Descripción',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Ruta base activa',
                'required' => false,
            ])
            ->add('steps', CollectionType::class, [
                'entry_type' => ProductionRouteTemplateStepType::class,
                'entry_options' => ['operations' => $options['operations']],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'label' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('operations');
        $resolver->setAllowedTypes('operations', 'array');
        $resolver->setDefaults(['data_class' => ProductionRouteTemplateData::class]);
    }
}
