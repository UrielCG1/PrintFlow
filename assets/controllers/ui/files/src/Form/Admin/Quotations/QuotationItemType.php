<?php

namespace App\Form\Admin\Quotations;

use App\Application\Quotations\QuotationItemData;
use App\Entity\Catalog\CommercialCategory;
use App\Entity\Catalog\CommercialItem;
use App\Repository\Catalog\CommercialCategoryRepository;
use App\Repository\Catalog\CommercialItemRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class QuotationItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('commercialCategory', EntityType::class, [
                'class' => CommercialCategory::class,
                'label' => 'Categoría',
                'placeholder' => 'Selecciona una categoría',
                'query_builder' => static function (CommercialCategoryRepository $repository) {
                    return $repository
                        ->createQueryBuilder('category')
                        ->andWhere('category.isActive = :isActive')
                        ->setParameter('isActive', true)
                        ->orderBy('category.displayOrder', 'ASC')
                        ->addOrderBy('category.name', 'ASC');
                },
                'choice_label' => static fn (CommercialCategory $category): string => $category->getName(),
                'attr' => [
                    'data-ui--quotation-form-commercial-category' => '',
                    'data-action' => 'change->ui--quotation-form#changeCommercialCategory',
                ],
            ])
            ->add('commercialItem', EntityType::class, [
                'class' => CommercialItem::class,
                'label' => 'Producto',
                'placeholder' => 'Selecciona un producto',
                'query_builder' => static function (
                    CommercialItemRepository $repository,
                ) {
                    return $repository
                        ->createQueryBuilder('item')
                        ->innerJoin('item.category', 'category')
                        ->innerJoin('item.measurementUnit', 'measurementUnit')
                        ->addSelect('category', 'measurementUnit')
                        ->andWhere('item.isActive = :isActive')
                        ->setParameter('isActive', true)
                        ->orderBy('item.name', 'ASC')
                        ->addOrderBy('item.code', 'ASC');
                },
                'choice_label' => static fn (CommercialItem $item): string => sprintf(
                    '%s — %s (%s)',
                    $item->getCode(),
                    $item->getName(),
                    $item->getMeasurementUnit()->getName(),
                ),
                'choice_attr' => static fn (CommercialItem $item): array => [
                    'data-quotation-category-id' => (string) $item->getCategory()->getId(),
                    'data-quotation-profile' => $item->getQuotationSpecificationProfile()->value,
                    'data-quotation-measurement-unit-code' => $item->getMeasurementUnit()->getCode(),
                    'data-quotation-measurement-unit-name' => $item->getMeasurementUnit()->getName(),
                ],
                'attr' => [
                    'data-ui--quotation-form-commercial-item' => '',
                    'data-action' => 'change->ui--quotation-form#changeCommercialItem',
                ],
            ])
            ->add('quantity', TextType::class, [
                'label' => 'Cantidad',
                'attr' => [
                    'inputmode' => 'decimal',
                    'placeholder' => '1.0000',
                    'data-ui--quotation-form-quantity' => '',
                    'data-action' => 'input->ui--quotation-form#markQuantityAsManual',
                ],
            ])
            ->add('quantityMode', HiddenType::class, [
                'attr' => [
                    'data-ui--quotation-form-quantity-mode' => '',
                ],
            ])
            ->add('specifications', CollectionType::class, [
                'entry_type' => TextType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => QuotationItemData::class,
        ]);
    }
}
