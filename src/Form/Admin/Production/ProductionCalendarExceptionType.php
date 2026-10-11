<?php

declare(strict_types=1);

namespace App\Form\Admin\Production;

use App\Application\Production\ProductionCalendarExceptionData;
use App\Entity\Equipment\Equipment;
use App\Enum\Production\ProductionCalendarExceptionType as ExceptionType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProductionCalendarExceptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', EnumType::class, [
                'class' => ExceptionType::class,
                'label' => 'Excepción',
                'choice_label' => static fn (ExceptionType $type): string => $type->label(),
                'placeholder' => 'Selecciona',
            ])
            ->add('equipment', EntityType::class, [
                'class' => Equipment::class,
                'choices' => $options['equipment_choices'],
                'choice_label' => static fn (Equipment $equipment): string => $equipment->getCode().' — '.$equipment->getName(),
                'label' => 'Máquina',
                'required' => false,
                'placeholder' => 'Toda la planta / feriado',
                'help' => 'Mantenimiento y avería requieren máquina. Horas extra pueden aplicar a toda la planta.',
            ])
            ->add('startsAt', DateTimeType::class, [
                'label' => 'Inicio (hora de Ciudad de México)',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'model_timezone' => 'UTC',
                'view_timezone' => 'America/Mexico_City',
            ])
            ->add('endsAt', DateTimeType::class, [
                'label' => 'Fin (hora de Ciudad de México)',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'model_timezone' => 'UTC',
                'view_timezone' => 'America/Mexico_City',
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'Motivo / autorización',
                'attr' => ['rows' => 3, 'maxlength' => 500],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('equipment_choices');
        $resolver->setAllowedTypes('equipment_choices', 'array');
        $resolver->setDefaults(['data_class' => ProductionCalendarExceptionData::class]);
    }
}
