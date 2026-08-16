<?php

namespace App\Form\Admin\Clients;

use App\Application\Clients\ClientData;
use App\Entity\Clients\ClientCategory;
use App\Repository\Clients\ClientCategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ClientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currentCategory = $options['current_category'];

        $builder
            ->add('clientType', ChoiceType::class, ['label'=>'Tipo de cliente','choices'=>['Empresa'=>'COMPANY','Persona física'=>'INDIVIDUAL']])
            ->add('businessName', TextType::class, [
                'label' => 'Nombre comercial',
                'attr' => [
                    'autocomplete' => 'organization',
                    'maxlength' => 160,
                ],
            ])
            ->add('category', EntityType::class, [
                'class' => ClientCategory::class,
                'choice_label' => static fn (ClientCategory $category): string => sprintf(
                    '%s%s',
                    $category->getName(),
                    $category->isActive() ? '' : ' (inactiva)',
                ),
                'placeholder' => 'Sin categoría asignada',
                'required' => false,
                'query_builder' => static function (
                    ClientCategoryRepository $repository,
                ) use ($currentCategory) {
                    $queryBuilder = $repository
                        ->createQueryBuilder('category')
                        ->orderBy('category.displayOrder', 'ASC')
                        ->addOrderBy('category.name', 'ASC');

                    if ($currentCategory === null) {
                        return $queryBuilder
                            ->andWhere('category.isActive = :isActive')
                            ->setParameter('isActive', true);
                    }

                    return $queryBuilder
                        ->andWhere(
                            'category.isActive = :isActive
                            OR category = :currentCategory'
                        )
                        ->setParameter('isActive', true)
                        ->setParameter('currentCategory', $currentCategory);
                },
            ])
            ->add('email', EmailType::class, [
                'label' => 'Correo general',
                'required' => false,
                'attr' => [
                    'autocomplete' => 'email',
                    'maxlength' => 180,
                ],
            ])
            ->add('phone', TextType::class, [
                'label' => 'Teléfono general',
                'required' => false,
                'attr' => [
                    'autocomplete' => 'tel',
                    'maxlength' => 40,
                ],
            ])
            ->add('legalName', TextType::class, [
                'label' => 'Razón social',
                'required' => false,
                'attr' => ['maxlength' => 160],
            ])
            ->add('businessActivity', TextType::class, ['label'=>'Giro','required'=>false,'attr'=>['maxlength'=>160]])
            ->add('website', TextType::class, ['label'=>'Sitio web','required'=>false,'attr'=>['maxlength'=>255]])
            ->add('birthDate', DateType::class, ['label'=>'Cumpleaños (persona física)','required'=>false,'widget'=>'single_text','input'=>'datetime_immutable'])
            ->add('taxId', TextType::class, [
                'label' => 'RFC',
                'required' => false,
                'help' => 'Debe ser único cuando se capture.',
                'attr' => [
                    'maxlength' => 20,
                    'style' => 'text-transform: uppercase',
                ],
            ])
            ->add('taxRegimeCode', TextType::class, [
                'label' => 'Régimen fiscal',
                'required' => false,
                'attr' => [
                    'inputmode' => 'numeric',
                    'maxlength' => 3,
                    'pattern' => '\d{3}',
                ],
            ])
            ->add('billingEmail', EmailType::class, [
                'label' => 'Correo de facturación',
                'required' => false,
                'attr' => [
                    'autocomplete' => 'email',
                    'maxlength' => 180,
                ],
            ])
            ->add('defaultCfdiUseCode', TextType::class, [
                'label' => 'Uso CFDI predeterminado',
                'required' => false,
                'attr' => [
                    'maxlength' => 10,
                    'style' => 'text-transform: uppercase',
                ],
            ])
            ->add('defaultDiscountPercent', NumberType::class, [
                'label' => 'Descuento predeterminado',
                'help' => 'Se propondrá en futuras cotizaciones; podrá ajustarse por documento.',
                'scale' => 2,
                'input' => 'number',
                'attr' => [
                    'min' => 0,
                    'max' => 100,
                    'step' => 0.01,
                    'inputmode' => 'decimal',
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notas internas',
                'required' => false,
                'attr' => [
                    'maxlength' => 2000,
                    'rows' => 4,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ClientData::class,
            'current_category' => null,
        ]);

        $resolver->setAllowedTypes('current_category', [
            'null',
            ClientCategory::class,
        ]);
    }
}
