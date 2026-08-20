<?php

declare(strict_types=1);

namespace App\Form\Admin\Catalog;

use App\Application\Catalog\CommercialItemCharacteristicData;
use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialCharacteristicOption;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CommercialItemCharacteristicType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var CommercialCharacteristic $characteristic */
        $characteristic = $options['characteristic'];

        $builder->add('isRequired', CheckboxType::class, [
            'label' => 'Solicitar esta característica obligatoriamente al cotizar',
            'required' => false,
            'help' => 'Si está activa, la partida no podrá guardarse sin capturar este dato.',
        ]);

        if ($characteristic->getInputType()->supportsOptions()) {
            $builder->add('allowedOptions', EntityType::class, [
                'class' => CommercialCharacteristicOption::class,
                'choices' => $options['available_options'],
                'multiple' => true,
                'expanded' => true,
                'label' => 'Opciones permitidas',
                'help' => 'Selecciona qué valores de la característica puede ofrecer específicamente este Producto. Al menos una opción es obligatoria.',
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
