<?php

namespace App\Form\Admin\Catalog;

use App\Application\Catalog\CommercialItemBasePriceData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CommercialItemBasePriceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('basePrice', TextType::class, [
            'label' => 'Precio base unitario (MXN)',
            'help' => 'Se utiliza cuando no existe un rango activo aplicable a la cantidad cotizada.',
            'attr' => [
                'inputmode' => 'decimal',
                'placeholder' => '0.00',
                'maxlength' => 13,
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CommercialItemBasePriceData::class,
        ]);
    }
}
