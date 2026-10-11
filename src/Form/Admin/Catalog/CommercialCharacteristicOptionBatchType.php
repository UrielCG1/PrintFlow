<?php

declare(strict_types=1);

namespace App\Form\Admin\Catalog;

use App\Application\Catalog\CommercialCharacteristicOptionBatchData;
use App\Entity\Catalog\CommercialCharacteristic;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CommercialCharacteristicOptionBatchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('options', CollectionType::class, [
            'entry_type' => CommercialCharacteristicOptionType::class,
            'entry_options' => ['characteristic' => $options['characteristic']],
            'allow_add' => true,
            'allow_delete' => true,
            'by_reference' => false,
            'prototype' => true,
            'label' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CommercialCharacteristicOptionBatchData::class]);
        $resolver->setRequired('characteristic');
        $resolver->setAllowedTypes('characteristic', CommercialCharacteristic::class);
    }
}
