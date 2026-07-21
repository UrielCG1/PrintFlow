<?php

namespace App\Form\Admin\Access;

use App\DTO\Access\ResetUserPasswordData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class ResetUserPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('temporaryPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => [
                'label' => 'Nueva contraseña temporal',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'second_options' => [
                'label' => 'Confirmar contraseña temporal',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'invalid_message' => 'Las contraseñas no coinciden.',
            'constraints' => [
                new Assert\NotBlank(),
                new Assert\Length(min: 12),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ResetUserPasswordData::class,
        ]);
    }
}