<?php

declare(strict_types=1);

namespace App\Form\Admin\Catalog;

use App\Application\Catalog\CommercialCharacteristicData;
use App\Enum\Catalog\CommercialCharacteristicInputType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CommercialCharacteristicType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array{code: string, label: string, inputType: CommercialCharacteristicInputType, unitLabel: string, description: string}|null $technicalContract */
        $technicalContract = $options['technical_contract'];
        $protected = $technicalContract !== null;
        $definitionLocked = $protected || $options['lock_definition'];

        $builder
            ->add('code', TextType::class, [
                'label' => 'Código técnico',
                'help' => $protected
                    ? 'Protegido porque el cotizador utiliza este identificador internamente.'
                    : ($definitionLocked
                        ? 'El código queda protegido mientras la característica esté configurada en uno o más Productos.'
                        : 'Identificador técnico único. Ejemplo: ADHESIVE_TYPE. Evita cambiarlo cuando ya existan integraciones que lo utilicen.'),
                'disabled' => $definitionLocked,
                'attr' => ['maxlength' => 60, 'autocomplete' => 'off', 'style' => 'text-transform: uppercase'],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre visible',
                'help' => 'Es el nombre que verá el personal al configurar Productos y capturar cotizaciones.',
                'attr' => ['maxlength' => 100, 'autocomplete' => 'off'],
            ])
            ->add('inputType', EnumType::class, [
                'class' => CommercialCharacteristicInputType::class,
                'label' => 'Tipo de captura',
                'choice_label' => static fn (CommercialCharacteristicInputType $type): string => $type->label(),
                'help' => $protected
                    ? 'El tipo de captura está fijado por el contrato técnico del cotizador.'
                    : ($definitionLocked
                        ? 'El tipo queda protegido mientras la característica esté configurada en Productos.'
                        : '“Lista de opciones” permite administrar un catálogo controlado de valores.'),
                'disabled' => $definitionLocked,
            ])
            ->add('unitLabel', TextType::class, [
                'label' => 'Unidad visible',
                'required' => false,
                'help' => $protected
                    ? 'La unidad está fijada por el cálculo especializado de Gran formato.'
                    : ($definitionLocked
                        ? 'La unidad queda protegida mientras la característica esté configurada en Productos.'
                        : 'Opcional. Úsala únicamente cuando ayude a interpretar el valor, por ejemplo cm o g/m².'),
                'disabled' => $definitionLocked,
                'attr' => ['maxlength' => 20, 'autocomplete' => 'off'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CommercialCharacteristicData::class,
            'technical_contract' => null,
            'lock_definition' => false,
        ]);
        $resolver->setAllowedTypes('technical_contract', ['null', 'array']);
        $resolver->setAllowedTypes('lock_definition', 'bool');
    }
}
