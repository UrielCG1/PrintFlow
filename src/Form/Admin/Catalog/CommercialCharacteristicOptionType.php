<?php

declare(strict_types=1);

namespace App\Form\Admin\Catalog;

use App\Application\Catalog\CommercialCharacteristicOptionData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CommercialCharacteristicOptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Código',
                'help' => 'Identificador técnico único dentro de esta característica. Ejemplo: MATTE.',
                'attr' => ['maxlength' => 60, 'autocomplete' => 'off', 'style' => 'text-transform: uppercase'],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre visible',
                'attr' => ['maxlength' => 100, 'autocomplete' => 'off'],
            ])
            ->add('displayOrder', IntegerType::class, [
                'label' => 'Orden de visualización',
                'attr' => ['min' => 0],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CommercialCharacteristicOptionData::class]);
    }
}
