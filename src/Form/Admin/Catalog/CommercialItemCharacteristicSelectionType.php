<?php

declare(strict_types=1);

namespace App\Form\Admin\Catalog;

use App\Application\Catalog\CommercialItemCharacteristicSelectionData;
use App\Entity\Catalog\CommercialCharacteristic;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CommercialItemCharacteristicSelectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('characteristic', EntityType::class, [
            'class' => CommercialCharacteristic::class,
            'choices' => $options['characteristics'],
            'label' => 'Característica',
            'placeholder' => 'Selecciona una característica',
            'choice_label' => static fn (CommercialCharacteristic $characteristic): string => sprintf(
                '%s%s · %s',
                $characteristic->getName(),
                $characteristic->getUnitLabel() !== null ? ' ('.$characteristic->getUnitLabel().')' : '',
                $characteristic->getInputType()->label(),
            ),
            'help' => 'Solo se muestran características activas que todavía no están configuradas en este Producto.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CommercialItemCharacteristicSelectionData::class,
            'characteristics' => [],
        ]);
        $resolver->setAllowedTypes('characteristics', 'array');
    }
}
