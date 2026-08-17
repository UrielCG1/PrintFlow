<?php

namespace App\Form\Admin\Suppliers;

use App\Application\Suppliers\SupplierData;
use App\Application\Suppliers\{SupplierBranchData,SupplierInlineContactData};
use App\Application\Clients\{ClientBranchAddressData,ClientPhoneData};
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SupplierType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Código',
                'help' => 'Identificador interno único. Ejemplo: PROV-LONA-01.',
                'attr' => [
                    'autocomplete' => 'off',
                    'maxlength' => 80,
                    'style' => 'text-transform: uppercase',
                ],
            ])
            ->add('businessName', TextType::class, [
                'label' => 'Nombre comercial',
                'attr' => [
                    'autocomplete' => 'organization',
                    'maxlength' => 160,
                ],
            ])
            ->add('legalName', TextType::class, [
                'label' => 'Razón social',
                'required' => false,
                'attr' => [
                    'maxlength' => 160,
                ],
            ])
            ->add('taxId', TextType::class, [
                'label' => 'RFC',
                'required' => false,
                'help' => 'Debe ser único cuando se capture.',
                'attr' => [
                    'maxlength' => 20,
                    'style' => 'text-transform: uppercase',
                ],
            ])
            ->add('taxRegimeCode',TextType::class,['label'=>'Régimen fiscal','required'=>false,'attr'=>['maxlength'=>3]])
            ->add('billingEmail',EmailType::class,['label'=>'Correo de facturación','required'=>false])
            ->add('defaultCfdiUseCode',TextType::class,['label'=>'Uso CFDI predeterminado','required'=>false,'attr'=>['maxlength'=>10]])
            ->add('businessActivity', TextType::class, ['label'=>'Giro','required'=>false,'attr'=>['maxlength'=>160]])
            ->add('website', TextType::class, ['label'=>'Sitio web','required'=>false,'attr'=>['maxlength'=>255]])
            ->add('email', EmailType::class, [
                'label' => 'Correo general',
                'required' => false,
                'attr' => [
                    'autocomplete' => 'email',
                    'maxlength' => 180,
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notas internas',
                'required' => false,
                'attr' => [
                    'maxlength' => 2000,
                    'rows' => 4,
                ],
            ])
            ->add('phones',CollectionType::class,['label'=>'Teléfonos generales y fax','entry_type'=>\App\Form\Admin\Clients\ClientPhoneEntryType::class,'allow_add'=>true,'allow_delete'=>true,'by_reference'=>false,'prototype_name'=>'__supplier_phone__'])
            ->add('branches',CollectionType::class,['label'=>'Sucursales','entry_type'=>SupplierBranchEntryType::class,'allow_add'=>true,'allow_delete'=>true,'by_reference'=>false,'prototype_name'=>'__supplier_branch__','prototype_data'=>$this->newBranchData()]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SupplierData::class,
        ]);
    }
    private function newBranchData():SupplierBranchData{$branch=new SupplierBranchData();$branch->addresses[]=new ClientBranchAddressData();$branch->phones[]=new ClientPhoneData();$contact=new SupplierInlineContactData();$contact->phones[]=new ClientPhoneData();$branch->contacts[]=$contact;return $branch;}
}
