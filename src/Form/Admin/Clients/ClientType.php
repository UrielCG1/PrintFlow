<?php

namespace App\Form\Admin\Clients;

use App\Application\Clients\ClientData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ClientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('businessName', TextType::class, [
                'label' => 'Nombre o razón social',
                'attr' => [
                    'autocomplete' => 'organization',
                    'maxlength' => 160,
                ],
            ])
            ->add('taxId', TextType::class, [
                'label' => 'RFC o identificador fiscal',
                'required' => false,
                'help' => 'Opcional. Debe ser único cuando se capture.',
                'attr' => [
                    'maxlength' => 20,
                    'style' => 'text-transform: uppercase',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Correo electrónico',
                'required' => false,
                'attr' => [
                    'autocomplete' => 'email',
                    'maxlength' => 180,
                ],
            ])
            ->add('phone', TextType::class, [
                'label' => 'Teléfono',
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
            'data_class' => ClientData::class,
        ]);
    }
}