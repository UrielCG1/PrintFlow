<?php

declare(strict_types=1);

namespace App\Form\Admin\Operations;

use App\Application\Operations\OperationData;
use App\Entity\Operations\OperationArea;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class OperationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('operationArea', EntityType::class, [
                'class' => OperationArea::class,
                'choices' => $options['operation_areas'],
                'choice_label' => static fn (OperationArea $area): string => $area->getCode().' — '.$area->getName(),
                'label' => 'Área operativa',
                'placeholder' => 'Selecciona un área',
            ])
            ->add('code', TextType::class, [
                'label' => 'Código',
                'help' => 'Identificador único y permanente. Ejemplo: ACA-CORTE.',
                'attr' => [
                    'maxlength' => 40,
                    'autocomplete' => 'off',
                    'style' => 'text-transform: uppercase',
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => [
                    'maxlength' => 120,
                    'autocomplete' => 'off',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Descripción',
                'required' => false,
                'attr' => [
                    'maxlength' => 65535,
                    'rows' => 4,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('operation_areas');
        $resolver->setAllowedTypes('operation_areas', 'array');
        $resolver->setDefaults([
            'data_class' => OperationData::class,
        ]);
    }
}