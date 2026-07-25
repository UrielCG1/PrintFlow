<?php

namespace App\Form\Admin\Clients;

use App\Application\Clients\ClientAddressData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ClientAddressType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'Nombre de la dirección',
                'help' => 'Ejemplo: Oficina central, Planta Querétaro o Sucursal norte.',
                'attr' => ['maxlength' => 100],
            ])
            ->add('recipientName', TextType::class, [
                'label' => 'Destinatario o responsable',
                'required' => false,
                'attr' => ['maxlength' => 160],
            ])
            ->add('street', TextType::class, [
                'label' => 'Calle',
                'attr' => ['maxlength' => 160],
            ])
            ->add('exteriorNumber', TextType::class, [
                'label' => 'Número exterior',
                'attr' => ['maxlength' => 30],
            ])
            ->add('interiorNumber', TextType::class, [
                'label' => 'Número interior',
                'required' => false,
                'attr' => ['maxlength' => 30],
            ])
            ->add('neighborhood', TextType::class, [
                'label' => 'Colonia',
                'required' => false,
                'attr' => ['maxlength' => 120],
            ])
            ->add('postalCode', TextType::class, [
                'label' => 'Código postal',
                'attr' => [
                    'inputmode' => 'numeric',
                    'maxlength' => 5,
                    'pattern' => '\d{5}',
                ],
            ])
            ->add('municipality', TextType::class, [
                'label' => 'Municipio o alcaldía',
                'attr' => ['maxlength' => 120],
            ])
            ->add('state', TextType::class, [
                'label' => 'Estado',
                'attr' => ['maxlength' => 120],
            ])
            ->add('references', TextareaType::class, [
                'label' => 'Referencias de entrega',
                'required' => false,
                'attr' => [
                    'maxlength' => 1000,
                    'rows' => 3,
                ],
            ])
            ->add('isFiscalAddress', CheckboxType::class, [
                'label' => 'Puede utilizarse como dirección fiscal',
                'required' => false,
            ])
            ->add('isDeliveryAddress', CheckboxType::class, [
                'label' => 'Puede utilizarse como dirección de entrega',
                'required' => false,
            ])
            ->add('isDefaultFiscal', CheckboxType::class, [
                'label' => 'Usar como dirección fiscal predeterminada',
                'required' => false,
                'help' => 'Al marcarla, reemplazará la dirección fiscal predeterminada actual.',
            ])
            ->add('isDefaultDelivery', CheckboxType::class, [
                'label' => 'Usar como dirección de entrega predeterminada',
                'required' => false,
                'help' => 'Al marcarla, reemplazará la dirección de entrega predeterminada actual.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ClientAddressData::class,
        ]);
    }
}