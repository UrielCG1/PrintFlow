<?php

declare(strict_types=1);

namespace App\Form\Admin\Orders;

use App\Application\Orders\ServiceOrderPlanningData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
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
            'help' => 'Puede dejarse pendiente mientras construyes la ruta. Será obligatoria al marcar la orden como planificada.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ServiceOrderPlanningData::class,
        ]);
    }
}
