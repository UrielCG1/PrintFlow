<?php

namespace App\Form\Admin\Suppliers;

use App\Application\Suppliers\SupplierData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SupplierType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Código',
                'help' => 'Identificador interno único. Ejemplo: PROV-LONA-01.',
                'attr' => [
                    'autocomplete' => 'off',
                    'maxlength' => 80,
                    'style' => 'text-transform: uppercase',
                ],
            ])
            ->add('businessName', TextType::class, [
                'label' => 'Nombre comercial',
                'attr' => [
                    'autocomplete' => 'organization',
                    'maxlength' => 160,
                ],
            ])
            ->add('legalName', TextType::class, [
                'label' => 'Razón social',
                'required' => false,
                'attr' => [
                    'maxlength' => 160,
                ],
            ])
            ->add('taxId', TextType::class, [
                'label' => 'RFC',
                'required' => false,
                'help' => 'Debe ser único cuando se capture.',
                'attr' => [
                    'maxlength' => 20,
                    'style' => 'text-transform: uppercase',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Correo general',
                'required' => false,
                'attr' => [
                    'autocomplete' => 'email',
                    'maxlength' => 180,
                ],
            ])
            ->add('phone', TextType::class, [
                'label' => 'Teléfono general',
                'required' => false,
                'attr' => [
                    'autocomplete' => 'tel',
                    'maxlength' => 40,
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notas internas',
                'required' => false,
                'attr' => [
                    'maxlength' => 2000,
                    'rows' => 4,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SupplierData::class,
        ]);
    }
}