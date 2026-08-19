<?php

declare(strict_types=1);

namespace App\Form\Admin\Catalog;

use App\Application\Catalog\CommercialItemCharacteristicData;
use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialCharacteristicOption;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CommercialItemCharacteristicType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var CommercialCharacteristic $characteristic */
        $characteristic = $options['characteristic'];

        $builder
            ->add('isRequired', CheckboxType::class, [
                'label' => 'Solicitar esta característica obligatoriamente al cotizar',
                'required' => false,
            ])
            ->add('displayOrder', IntegerType::class, [
                'label' => 'Orden de visualización',
                'attr' => ['min' => 0],
            ]);

        if ($characteristic->getInputType()->supportsOptions()) {
            $builder->add('allowedOptions', EntityType::class, [
                'class' => CommercialCharacteristicOption::class,
                'choices' => $options['available_options'],
                'multiple' => true,
                'expanded' => true,
                'label' => 'Opciones permitidas',
                'help' => 'Selecciona las opciones que este Producto puede ofrecer. Al menos una es obligatoria.',
                'choice_label' => static fn (CommercialCharacteristicOption $option): string => $option->getName(),
                'choice_attr' => static fn (CommercialCharacteristicOption $option): array => $option->isActive()
                    ? []
                    : ['data-option-inactive' => 'true'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CommercialItemCharacteristicData::class,
            'characteristic' => null,
            'available_options' => [],
        ]);
        $resolver->setAllowedTypes('characteristic', CommercialCharacteristic::class);
        $resolver->setAllowedTypes('available_options', 'array');
    }
}
