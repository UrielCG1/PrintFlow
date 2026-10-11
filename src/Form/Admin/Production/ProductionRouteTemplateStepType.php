<?php

declare(strict_types=1);

namespace App\Form\Admin\Production;

use App\Application\Production\ProductionRouteStepData;
use App\Entity\Operations\Operation;
use App\Enum\Production\RouteCondition;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProductionRouteTemplateStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('operation', EntityType::class, [
                'class' => Operation::class,
                'choices' => $options['operations'],
                'choice_label' => static fn (Operation $operation): string => sprintf(
                    '%s — %s (%s)',
                    $operation->getCode(),
                    $operation->getName(),
                    $operation->getOperationArea()->getName(),
                ),
                'placeholder' => 'Selecciona una operación',
                'required' => false,
                'label' => 'Operación',
            ])
            ->add('condition', ChoiceType::class, [
                'choices' => RouteCondition::cases(),
                'choice_label' => static fn (RouteCondition $condition): string => $condition->label(),
                'choice_value' => static fn (?RouteCondition $condition): string => $condition?->value ?? '',
                'label' => 'Incluir cuando',
            ])
            ->add('specificationKey', TextType::class, [
                'label' => 'Clave técnica',
                'required' => false,
                'attr' => ['maxlength' => 80, 'placeholder' => 'Ej. FINISHED_WIDTH_CM'],
            ])
            ->add('specificationValue', TextType::class, [
                'label' => 'Valor esperado',
                'required' => false,
                'attr' => ['maxlength' => 120],
            ])
            ->add('isRequired', CheckboxType::class, [
                'label' => 'Paso obligatorio',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('operations');
        $resolver->setAllowedTypes('operations', 'array');
        $resolver->setDefaults(['data_class' => ProductionRouteStepData::class]);
    }
}
