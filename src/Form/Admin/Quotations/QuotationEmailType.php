<?php

declare(strict_types=1);

namespace App\Form\Admin\Quotations;

use App\Application\Quotations\QuotationEmailData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class QuotationEmailType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('recipientEmail', EmailType::class, [
                'label' => 'Correo destinatario',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('recipientName', TextType::class, [
                'label' => 'Nombre del destinatario',
                'required' => false,
                'attr' => ['maxlength' => 160],
            ])
            ->add('copyEmail', EmailType::class, [
                'label' => 'Enviar copia a',
                'required' => false,
                'help' => 'Opcional. Se registra como parte de la evidencia del envío.',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Mensaje adicional',
                'required' => false,
                'help' => 'Se agrega al correo junto con el PDF de la cotización.',
                'attr' => ['rows' => 4, 'maxlength' => 1000],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuotationEmailData::class]);
    }
}
