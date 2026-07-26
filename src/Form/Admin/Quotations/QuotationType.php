<?php

namespace App\Form\Admin\Quotations;

use App\Application\Quotations\QuotationData;
use App\Application\Quotations\QuotationItemData;
use App\Entity\Clients\Client;
use App\Repository\Clients\ClientRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class QuotationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('client', EntityType::class, [
                'class' => Client::class,
                'label' => 'Cliente',
                'placeholder' => 'Selecciona un cliente',
                'query_builder' => static function (
                    ClientRepository $repository,
                ) {
                    return $repository
                        ->createQueryBuilder('client')
                        ->andWhere('client.isActive = :isActive')
                        ->setParameter('isActive', true)
                        ->orderBy('client.businessName', 'ASC');
                },
                'choice_label' => static fn (Client $client): string => $client->getBusinessName(),
            ])
            ->add('expiresAt', DateType::class, [
                'label' => 'Vigencia',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('discountPercent', TextType::class, [
                'label' => 'Descuento global (%)',
                'required' => false,
                'help' => 'Vacío: aplica el descuento predeterminado del cliente.',
                'attr' => [
                    'inputmode' => 'decimal',
                    'placeholder' => '0.00',
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notas internas',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'maxlength' => 5000,
                ],
            ])
            ->add('items', CollectionType::class, [
                'entry_type' => QuotationItemType::class,
                'entry_options' => [
                    'label' => false,
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'delete_empty' => static fn (?QuotationItemData $item): bool => (
                    $item === null || $item->commercialItem === null
                ),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => QuotationData::class,
        ]);
    }
}