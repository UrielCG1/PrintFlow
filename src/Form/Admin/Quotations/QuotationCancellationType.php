<?php

declare(strict_types=1);

namespace App\Form\Admin\Quotations;

use App\Application\Quotations\QuotationCancellationData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class QuotationCancellationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('reason', TextareaType::class, [
            'label' => 'Motivo de cancelación',
            'attr' => ['rows' => 3, 'maxlength' => 2000],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuotationCancellationData::class]);
    }
}
