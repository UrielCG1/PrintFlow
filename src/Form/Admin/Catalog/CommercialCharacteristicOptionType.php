<?php

declare(strict_types=1);

namespace App\Form\Admin\Catalog;

use App\Application\Catalog\CommercialCharacteristicOptionData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CommercialCharacteristicOptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Código técnico',
                'help' => $options['lock_code']
                    ? 'El código queda protegido mientras esta opción esté permitida en uno o más Productos.'
                    : 'Identificador único dentro de esta característica. Ejemplo: MATTE.',
                'disabled' => $options['lock_code'],
                'attr' => ['maxlength' => 60, 'autocomplete' => 'off', 'style' => 'text-transform: uppercase'],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre visible',
                'help' => 'Es el valor que se mostrará al personal cuando capture esta característica.',
                'attr' => ['maxlength' => 100, 'autocomplete' => 'off'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CommercialCharacteristicOptionData::class,
            'lock_code' => false,
        ]);
        $resolver->setAllowedTypes('lock_code', 'bool');
    }
}
