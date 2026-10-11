<?php

declare(strict_types=1);

namespace App\Form\Admin\Production;

use App\Application\Production\CommercialItemRouteData;
use App\Entity\Production\ProductionRouteTemplate;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CommercialItemRouteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sourceTemplate', EntityType::class, [
                'class' => ProductionRouteTemplate::class,
                'choices' => $options['templates'],
                'choice_label' => static fn (ProductionRouteTemplate $template): string => $template->getCode().' — '.$template->getName(),
                'placeholder' => 'Selecciona una ruta base',
                'label' => 'Ruta base reutilizable',
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
        $resolver->setRequired(['templates', 'operations']);
        $resolver->setAllowedTypes('templates', 'array');
        $resolver->setAllowedTypes('operations', 'array');
        $resolver->setDefaults(['data_class' => CommercialItemRouteData::class]);
    }
}
