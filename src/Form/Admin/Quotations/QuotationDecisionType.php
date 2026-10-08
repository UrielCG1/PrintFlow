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
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class QuotationDecisionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $data = $builder->getData();
        if ($data instanceof QuotationDecisionData) $data->acceptanceFiles = $options['acceptance_files'];

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
                'help' => 'Indica una observación o una referencia para documentar la respuesta. La captura de WhatsApp también sirve como evidencia.',
                'attr' => ['maxlength' => 500],
            ])
            ->add('responseScreenshot', FileType::class, [
                'label' => 'Captura de la respuesta por WhatsApp',
                'required' => false,
                'help' => $options['acceptance_files'] ? 'Obligatoria al aceptar por WhatsApp. Máximo 10 MB.' : 'Opcional al rechazar por WhatsApp. Máximo 10 MB.',
                'attr' => ['accept' => 'image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp'],
            ])
            ;

        if ($options['acceptance_files']) {
            $builder
                ->add('purchaseOrderNumber', TextType::class, [
                    'label' => 'Folio de orden (opcional)',
                    'required' => false,
                    'help' => 'Solo se requiere este folio si adjuntas el PDF de la orden.',
                    'attr' => ['maxlength' => 120],
                ])
                ->add('purchaseOrderFile', FileType::class, [
                    'label' => 'Adjuntar PDF de la orden (opcional)',
                    'required' => false,
                    'mapped' => true,
                    'help' => 'Puedes registrar la aceptación sin adjuntar este documento. Máximo 20 MB.',
                    'attr' => ['accept' => 'application/pdf,.pdf'],
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuotationDecisionData::class, 'acceptance_files' => false]);
        $resolver->setAllowedTypes('acceptance_files', 'bool');
    }
}
