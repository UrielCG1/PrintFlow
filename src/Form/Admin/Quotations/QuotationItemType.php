<?php

namespace App\Form\Admin\Quotations;

use App\Application\Quotations\QuotationItemData;
use App\Entity\Catalog\CommercialItem;
use App\Repository\Catalog\CommercialItemRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class QuotationItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('commercialItem', EntityType::class, [
                'class' => CommercialItem::class,
                'label' => 'Concepto',
                'placeholder' => 'Selecciona un concepto comercial',
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
            ])
            ->add('quantity', TextType::class, [
                'label' => 'Cantidad',
                'attr' => [
                    'inputmode' => 'decimal',
                    'placeholder' => '1.0000',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => QuotationItemData::class,
        ]);
    }
}