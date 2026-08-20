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
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
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
                'attr' => [
                    'data-ui--quotation-form-target' => 'client',
                    'data-action' => 'change->ui--quotation-form#changeClient',
                ],
            ])
            ->add('expiresAt', DateType::class, [
                'label' => 'Vigencia hasta',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Último día en que esta propuesta comercial será válida.',
            ])
            ->add('discountPercent', TextType::class, [
                'label' => 'Descuento global (%)',
                'required' => false,
                'help' => 'Se propone el descuento predeterminado del cliente; puedes ajustarlo para este borrador.',
                'attr' => [
                    'inputmode' => 'decimal',
                    'placeholder' => '0.00',
                    'data-ui--quotation-form-target' => 'discountPercent',
                    'data-action' => 'input->ui--quotation-form#markDiscountAsManual',
                ],
            ])
            ->add('commercialContactId', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'data-ui--quotation-form-target' => 'commercialContactId',
                ],
            ])
            ->add('fiscalAddressId', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'data-ui--quotation-form-target' => 'fiscalAddressId',
                ],
            ])
            ->add('deliveryAddressId', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'data-ui--quotation-form-target' => 'deliveryAddressId',
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Observaciones de la cotización',
                'required' => false,
                'help' => 'Estas observaciones forman parte de la cotización y pueden mostrarse en el PDF enviado al cliente.',
                'attr' => [
                    'rows' => 4,
                    'maxlength' => 5000,
                    'placeholder' => 'Condiciones, alcances o aclaraciones relevantes para esta propuesta.',
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
