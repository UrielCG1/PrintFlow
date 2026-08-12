<?php

declare(strict_types=1);

namespace App\Form\Admin\Orders;

use App\Application\Orders\ServiceOrderItemOperationEquipmentData;
use App\Entity\Equipment\Equipment;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ServiceOrderItemOperationEquipmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('equipment', EntityType::class, [
            'class' => Equipment::class,
            'choices' => $options['equipment'],
            'choice_label' => static fn (Equipment $equipment): string => sprintf(
                '%s — %s%s',
                $equipment->getCode(),
                $equipment->getName(),
                $equipment->isSelectableForFutureExecution() ? '' : ' (no disponible)',
            ),
            'placeholder' => 'Sin equipo asignado',
            'required' => false,
            'label' => 'Equipo',
            'help' => 'La asignación es opcional. Solo se ofrecen equipos disponibles compatibles con esta operación.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('equipment');
        $resolver->setAllowedTypes('equipment', 'array');
        $resolver->setDefaults([
            'data_class' => ServiceOrderItemOperationEquipmentData::class,
        ]);
    }
}
