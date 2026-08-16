<?php

namespace App\Form\Admin\Clients;

use App\Application\Clients\ClientContactData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ClientContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isContactActive = $options['contact_is_active'];

        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Nombre completo',
                'attr' => ['maxlength' => 160],
            ])
            ->add('jobTitle', TextType::class, [
                'label' => 'Puesto o área',
                'required' => false,
                'attr' => ['maxlength' => 120],
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
            ->add('workDays', TextType::class, [
                'label' => 'Días laborales',
                'required' => false,
                'help' => 'Ejemplo: Lunes a viernes.',
                'attr' => ['maxlength' => 100],
            ])
            ->add('workHours', TextType::class, [
                'label' => 'Horario laboral',
                'required' => false,
                'help' => 'Ejemplo: 09:00 a 18:00.',
                'attr' => ['maxlength' => 160],
            ])
            ->add('isPrimary', CheckboxType::class, [
                'label' => 'Usar como contacto principal',
                'required' => false,
                'disabled' => !$isContactActive,
                'help' => $isContactActive
                    ? 'Solo puede existir un contacto principal activo por cliente.'
                    : 'Reactiva el contacto antes de marcarlo como principal.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ClientContactData::class,
            'contact_is_active' => true,
        ]);

        $resolver->setAllowedTypes('contact_is_active', 'bool');
    }
}
