<?php

declare(strict_types=1);

namespace App\Form\Admin\Quotations;

use App\Application\Quotations\QuotationDecisionData;
use App\Enum\Quotations\QuotationResponseChannel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class QuotationDecisionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('channel', EnumType::class, [
                'class' => QuotationResponseChannel::class,
                'label' => 'Canal de respuesta',
                'placeholder' => 'Selecciona un canal',
                'choice_label' => static fn (QuotationResponseChannel $channel): string => $channel->label(),
            ])
            ->add('contact', TextType::class, [
                'label' => 'Contacto que respondió',
                'help' => 'Ejemplo: Ana López, contacto de Compras.',
                'attr' => ['maxlength' => 160],
            ])
            ->add('respondedAt', DateTimeType::class, [
                'label' => 'Fecha y hora de respuesta',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'model_timezone' => 'America/Mexico_City',
                'view_timezone' => 'America/Mexico_City',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Observación',
                'required' => false,
                'attr' => ['rows' => 4, 'maxlength' => 5000],
            ])
            ->add('evidenceReference', TextType::class, [
                'label' => 'Referencia de evidencia',
                'required' => false,
                'help' => 'Ejemplo: correo del 10/08/2026, enlace de WhatsApp o folio de llamada.',
                'attr' => ['maxlength' => 500],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuotationDecisionData::class]);
    }
}
