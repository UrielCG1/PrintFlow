<?php

namespace App\Form\Admin\Access;

use App\DTO\Access\CreateUserData;
use App\Entity\Users\Role;
use App\Repository\Users\RoleRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class CreateUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Nombre completo',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 160),
                ],
            ])
            ->add('username', TextType::class, [
                'label' => 'Usuario',
                'help' => 'De 3 a 60 caracteres: letras, números, punto, guion o guion bajo.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(min: 3, max: 60),
                    new Assert\Regex(
                        pattern: '/^[a-zA-Z0-9._-]+$/',
                        message: 'El usuario contiene caracteres no permitidos.',
                    ),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Correo electrónico',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Email(),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Teléfono',
                'required' => false,
                'constraints' => [
                    new Assert\Length(max: 30),
                ],
            ])
            ->add('roles', EntityType::class, [
                'class' => Role::class,
                'label' => 'Roles asignados',
                'choice_label' => static fn (Role $role): string => sprintf(
                    '%s (%s)',
                    $role->getName(),
                    $role->getCode(),
                ),
                'query_builder' => static fn (RoleRepository $repository) => $repository
                    ->createQueryBuilder('role')
                    ->andWhere('role.isActive = :active')
                    ->setParameter('active', true)
                    ->orderBy('role.name', 'ASC'),
                'multiple' => true,
                'expanded' => true,
                'constraints' => [
                    new Assert\Count(min: 1),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'Contraseña temporal',
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
            'data_class' => CreateUserData::class,
        ]);
    }
}