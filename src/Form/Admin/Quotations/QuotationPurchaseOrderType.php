<?php

declare(strict_types=1);

namespace App\Form\Admin\Quotations;

use App\Application\Quotations\QuotationPurchaseOrderData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class QuotationPurchaseOrderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('purchaseOrderNumber', TextType::class, [
                'label' => 'Folio de orden del cliente',
                'required' => false,
                'help' => 'Puedes registrar primero el folio y adjuntar el PDF cuando lo recibas.',
                'attr' => ['maxlength' => 120, 'placeholder' => 'Ej. OC-2026-0184'],
            ])
            ->add('purchaseOrderFile', FileType::class, [
                'label' => 'Orden de compra en PDF',
                'required' => false,
                'help' => 'Opcional al iniciar la orden. Puedes adjuntarla después; máximo 20 MB.',
                'attr' => ['accept' => 'application/pdf,.pdf'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuotationPurchaseOrderData::class]);
    }
}
