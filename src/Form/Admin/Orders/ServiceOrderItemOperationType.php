<?php

declare(strict_types=1);

namespace App\Form\Admin\Orders;

use App\Application\Orders\ServiceOrderItemOperationData;
use App\Entity\Operations\Operation;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ServiceOrderItemOperationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('operation', EntityType::class, [
            'class' => Operation::class,
            'choices' => $options['operations'],
            'choice_label' => static fn (Operation $operation): string => sprintf(
                '%s — %s (%s)',
                $operation->getCode(),
                $operation->getName(),
                $operation->getOperationArea()->getName(),
            ),
            'placeholder' => 'Selecciona una operación',
            'label' => 'Operación',
            'help' => 'Solo se muestran operaciones activas de áreas activas. La ruta se define manualmente para esta partida.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('operations');
        $resolver->setAllowedTypes('operations', 'array');
        $resolver->setDefaults([
            'data_class' => ServiceOrderItemOperationData::class,
        ]);
    }
}
