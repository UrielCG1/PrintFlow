<?php

namespace App\Form\Admin\Catalog;

use App\Application\Catalog\ItemPriceRuleData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ItemPriceRuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('minQuantity', TextType::class, [
                'label' => 'Cantidad mínima',
                'help' => 'El precio se aplicará desde esta cantidad en adelante.',
                'attr' => [
                    'inputmode' => 'decimal',
                    'placeholder' => 'Ejemplo: 100',
                    'maxlength' => 15,
                ],
            ])
            ->add('unitPrice', TextType::class, [
                'label' => 'Precio unitario (MXN)',
                'help' => 'Precio aplicable por cada unidad a partir del rango indicado.',
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
            'data_class' => ItemPriceRuleData::class,
        ]);
    }
}