<?php

declare(strict_types=1);

namespace App\Form\Admin\Orders;

use App\Application\Orders\ServiceOrderPlanningData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ServiceOrderPlanningType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('commitmentDate', DateType::class, [
            'label' => 'Fecha compromiso de entrega',
            'required' => false,
            'widget' => 'single_text',
            'input' => 'string',
            'help' => 'La primera fecha puede confirmarse sin motivo; ajustar una fecha existente requiere explicación.',
        ])->add('reason', TextareaType::class, [
            'label' => 'Motivo del cambio',
            'required' => false,
            'empty_data' => '',
            'attr' => ['rows' => 2, 'maxlength' => 500],
            'help' => 'Obligatorio cuando la fecha cambie.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ServiceOrderPlanningData::class,
        ]);
    }
}
