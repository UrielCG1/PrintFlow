<?php

declare(strict_types=1);

namespace App\Form\Admin\Catalog;

use App\Application\Catalog\CommercialCharacteristicData;
use App\Enum\Catalog\CommercialCharacteristicInputType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CommercialCharacteristicType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Código',
                'help' => 'Identificador técnico único. Ejemplo: ADHESIVE_TYPE.',
                'attr' => ['maxlength' => 60, 'autocomplete' => 'off', 'style' => 'text-transform: uppercase'],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre visible',
                'attr' => ['maxlength' => 100, 'autocomplete' => 'off'],
            ])
            ->add('inputType', EnumType::class, [
                'class' => CommercialCharacteristicInputType::class,
                'label' => 'Tipo de dato',
                'choice_label' => static fn (CommercialCharacteristicInputType $type): string => $type->label(),
                'help' => 'Solo “Lista de opciones” admite opciones como Mate, Brillante o Permanente.',
            ])
            ->add('unitLabel', TextType::class, [
                'label' => 'Unidad visible',
                'required' => false,
                'help' => 'Opcional. Ejemplo: cm o g/m².',
                'attr' => ['maxlength' => 20, 'autocomplete' => 'off'],
            ])
            ->add('displayOrder', IntegerType::class, [
                'label' => 'Orden de visualización',
                'attr' => ['min' => 0],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CommercialCharacteristicData::class]);
    }
}
