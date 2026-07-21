<?php

namespace App\Form\Account;

use App\DTO\Access\ChangePasswordData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Contraseña actual',
                'attr' => [
                    'autocomplete' => 'current-password',
                ],
                'constraints' => [
                    new Assert\NotBlank(
                        message: 'Ingresa tu contraseña actual.',
                    ),
                ],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'Nueva contraseña',
                    'attr' => [
                        'autocomplete' => 'new-password',
                    ],
                ],
                'second_options' => [
                    'label' => 'Confirmar nueva contraseña',
                    'attr' => [
                        'autocomplete' => 'new-password',
                    ],
                ],
                'invalid_message' => 'Las contraseñas no coinciden.',
                'constraints' => [
                    new Assert\NotBlank(
                        message: 'Ingresa una nueva contraseña.',
                    ),
                    new Assert\Length(
                        min: 12,
                        minMessage: 'La contraseña debe tener al menos {{ limit }} caracteres.',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ChangePasswordData::class,
        ]);
    }
}