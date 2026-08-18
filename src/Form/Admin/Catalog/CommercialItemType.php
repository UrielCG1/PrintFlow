<?php

namespace App\Form\Admin\Catalog;

use App\Application\Catalog\CommercialItemData;
use App\Entity\Catalog\CommercialCategory;
use App\Entity\Catalog\MeasurementUnit;
use App\Enum\Catalog\CommercialItemType as CommercialItemTypeEnum;
use App\Enum\Quotations\QuotationItemSpecificationProfile;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CommercialItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Código',
                'help' => 'Identificador interno único. Ejemplo: LONA-13OZ-IMP.',
                'attr' => [
                    'maxlength' => 80,
                    'autocomplete' => 'off',
                    'style' => 'text-transform: uppercase',
                ],
            ])
            ->add('type', EnumType::class, [
                'class' => CommercialItemTypeEnum::class,
                'label' => 'Tipo',
                'choice_label' => static fn (CommercialItemTypeEnum $type): string => $type->label(),
            ])
            ->add('quotationSpecificationProfile', EnumType::class, [
                'class' => QuotationItemSpecificationProfile::class,
                'label' => 'Especificaciones en cotización interna',
                'help' => 'Gran formato solicita ancho y alto terminados. Solo afecta /admin/cotizaciones.',
                'choice_label' => static fn (QuotationItemSpecificationProfile $profile): string => $profile->label(),
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => [
                    'maxlength' => 160,
                    'autocomplete' => 'off',
                ],
            ])
            ->add('category', EntityType::class, [
                'class' => CommercialCategory::class,
                'choices' => $options['categories'],
                'choice_label' => static fn (CommercialCategory $category): string => sprintf(
                    '%s — %s',
                    $category->getCode(),
                    $category->getName(),
                ),
                'label' => 'Categoría comercial',
                'placeholder' => 'Selecciona una categoría',
            ])
            ->add('measurementUnit', EntityType::class, [
                'class' => MeasurementUnit::class,
                'choices' => $options['measurement_units'],
                'choice_label' => static fn (MeasurementUnit $unit): string => sprintf(
                    '%s — %s',
                    $unit->getCode(),
                    $unit->getName(),
                ),
                'label' => 'Unidad de medida',
                'placeholder' => 'Selecciona una unidad',
            ])
            ->add('basePrice', TextType::class, [
                'label' => 'Precio base unitario (MXN)',
                'help' => $options['can_edit_price']
                    ? 'Precio antes de aplicar futuros rangos de cantidad.'
                    : 'No cuentas con permiso para modificar el precio.',
                'disabled' => !$options['can_edit_price'],
                'attr' => [
                    'inputmode' => 'decimal',
                    'placeholder' => '0.00',
                    'maxlength' => 13,
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
        $resolver->setDefaults([
            'data_class' => CommercialItemData::class,
            'categories' => [],
            'measurement_units' => [],
            'can_edit_price' => true,
        ]);

        $resolver->setAllowedTypes('categories', 'array');
        $resolver->setAllowedTypes('measurement_units', 'array');
        $resolver->setAllowedTypes('can_edit_price', 'bool');
    }
}
