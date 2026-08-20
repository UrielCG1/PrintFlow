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
            ;

        if ($options['acceptance_files']) {
            $builder
                ->add('purchaseOrderNumber', TextType::class, [
                    'label' => 'Número de orden de compra',
                    'required' => false,
                    'help' => 'Obligatorio cuando se adjunta una orden de compra.',
                    'attr' => ['maxlength' => 120],
                ])
                ->add('purchaseOrderFile', FileType::class, [
                    'label' => 'Orden de compra (PDF)',
                    'required' => false,
                    'mapped' => true,
                    'attr' => ['accept' => 'application/pdf,.pdf'],
                ])
                ->add('responseScreenshot', FileType::class, [
                    'label' => 'Captura de pantalla de la respuesta',
                    'required' => false,
                    'mapped' => true,
                    'help' => 'Obligatoria cuando el canal de respuesta es WhatsApp.',
                    'attr' => ['accept' => 'image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp'],
                ]);
        } else {
            $builder->add('evidenceReference', TextType::class, [
                'label' => 'Referencia de evidencia', 'required' => false,
                'help' => 'Ejemplo: correo, enlace de WhatsApp o folio de llamada.', 'attr' => ['maxlength' => 500],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuotationDecisionData::class, 'acceptance_files' => false]);
        $resolver->setAllowedTypes('acceptance_files', 'bool');
    }
}
