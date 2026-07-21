<?php

namespace App\Form\Admin\Access;

use App\DTO\Access\CreateRoleData;
use App\Entity\Users\Permission;
use App\Repository\Users\PermissionRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class CreateRoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Código técnico',
                'help' => 'Ejemplo: ROLE_DESIGNER. No podrá cambiarse después.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex('/^ROLE_[A-Z0-9_]+$/'),
                    new Assert\Length(max: 80),
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre del rol',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 100),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Descripción',
                'required' => false,
            ])
            ->add('permissions', EntityType::class, [
                'class' => Permission::class,
                'label' => 'Permisos',
                'choice_label' => static fn (Permission $permission): string => sprintf(
                    '%s · %s',
                    $permission->getModule(),
                    $permission->getName(),
                ),
                'query_builder' => static fn (PermissionRepository $repository) => $repository
                    ->createQueryBuilder('permission')
                    ->andWhere('permission.isActive = :active')
                    ->setParameter('active', true)
                    ->orderBy('permission.module', 'ASC')
                    ->addOrderBy('permission.name', 'ASC'),
                'multiple' => true,
                'expanded' => true,
                'constraints' => [
                    new Assert\Count(min: 1),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CreateRoleData::class,
        ]);
    }
}