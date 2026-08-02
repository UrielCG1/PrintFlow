<?php

namespace App\Form;

use App\Entity\Quotations\QuoteRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PublicQuoteRequestType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder
            ->add('customerNumber', TextType::class, [
                'label' => 'Número de cliente',
                'required' => false,
                'help' => 'Déjalo vacío si todavía no eres cliente.',
            ])
            ->add('fullName', TextType::class, [
                'label' => 'Nombre completo',
            ])
            ->add('email', EmailType::class, [
                'label' => 'Correo electrónico',
            ])
            ->add('phone', TelType::class, [
                'label' => 'Teléfono o WhatsApp',
            ])
            ->add('companyName', TextType::class, [
                'label' => 'Empresa',
                'required' => false,
            ])
            ->add('contactPreference', ChoiceType::class, [
                'label' => 'Medio de contacto preferido',
                'choices' => [
                    'WhatsApp' => 'whatsapp',
                    'Correo electrónico' => 'email',
                    'Llamada telefónica' => 'phone',
                ],
            ])
            ->add('productType', TextType::class, [
                'label' => 'Producto solicitado',
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'Cantidad',
                'attr' => [
                    'min' => 1,
                ],
            ])
            ->add('width', NumberType::class, [
                'label' => 'Ancho',
                'required' => false,
                'scale' => 2,
            ])
            ->add('height', NumberType::class, [
                'label' => 'Alto',
                'required' => false,
                'scale' => 2,
            ])
            ->add('measurementUnit', ChoiceType::class, [
                'label' => 'Unidad de medida',
                'required' => false,
                'placeholder' => 'Selecciona una unidad',
                'choices' => [
                    'Milímetros' => 'mm',
                    'Centímetros' => 'cm',
                    'Metros' => 'm',
                    'Pulgadas' => 'in',
                ],
            ])
            ->add('material', TextType::class, [
                'label' => 'Material',
                'required' => false,
            ])
            ->add('printSides', ChoiceType::class, [
                'label' => 'Tipo de impresión',
                'required' => false,
                'choices' => [
                    'Una cara' => 'one_side',
                    'Dos caras' => 'two_sides',
                    'Necesito asesoría' => 'undefined',
                ],
            ])
            ->add('finishes', ChoiceType::class, [
                'label' => 'Acabados',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    'Laminado' => 'laminated',
                    'Corte especial' => 'special_cut',
                    'Doblado' => 'folded',
                    'Barniz' => 'varnish',
                    'Ojillos' => 'eyelets',
                ],
            ])
            ->add('designStatus', ChoiceType::class, [
                'label' => 'Estado del diseño',
                'choices' => [
                    'Tengo el archivo listo' => 'ready',
                    'Necesita revisión' => 'needs_review',
                    'Necesito servicio de diseño' => 'needs_design',
                    'Todavía no tengo archivo' => 'no_file',
                ],
            ])
            ->add('neededAt', DateType::class, [
                'label' => 'Fecha requerida',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('deliveryMethod', ChoiceType::class, [
                'label' => 'Método de entrega',
                'choices' => [
                    'Recoger en sucursal' => 'pickup',
                    'Envío a domicilio' => 'shipping',
                    'Por definir' => 'undefined',
                ],
            ])
            ->add('postalCode', TextType::class, [
                'label' => 'Código postal',
                'required' => false,
            ])
            ->add('requiresInvoice', CheckboxType::class, [
                'label' => 'Requiero factura',
                'required' => false,
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Descripción o instrucciones adicionales',
                'required' => false,
                'attr' => [
                    'rows' => 5,
                ],
            ]);
    }

    public function configureOptions(
        OptionsResolver $resolver,
    ): void {
        $resolver->setDefaults([
            'data_class' => QuoteRequest::class,
            'csrf_token_id' => 'public_quote_request',
        ]);
    }
}