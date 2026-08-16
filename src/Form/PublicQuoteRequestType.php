<?php
namespace App\Form;
use App\Entity\Quotations\QuoteRequest; use Symfony\Component\Form\AbstractType; use Symfony\Component\Form\Extension\Core\Type\CheckboxType; use Symfony\Component\Form\Extension\Core\Type\ChoiceType; use Symfony\Component\Form\Extension\Core\Type\CollectionType; use Symfony\Component\Form\Extension\Core\Type\DateType; use Symfony\Component\Form\Extension\Core\Type\EmailType; use Symfony\Component\Form\Extension\Core\Type\TelType; use Symfony\Component\Form\Extension\Core\Type\TextType; use Symfony\Component\Form\FormBuilderInterface; use Symfony\Component\OptionsResolver\OptionsResolver;
final class PublicQuoteRequestType extends AbstractType {
 public function buildForm(FormBuilderInterface $b,array $o):void{$b
  ->add('existingCustomer',CheckboxType::class,['mapped'=>false,'required'=>false,'label'=>'Ya soy cliente'])
  ->add('customerNumber',TextType::class,['required'=>false,'label'=>'Número de cliente (ID de contacto)','attr'=>['inputmode'=>'numeric','autocomplete'=>'off']])
  ->add('deliveryAddressId',\Symfony\Component\Form\Extension\Core\Type\HiddenType::class,['mapped'=>false,'required'=>false])
  ->add('fullName',TextType::class,['label'=>'Nombre completo'])->add('email',EmailType::class,['label'=>'Correo electrónico'])->add('phone',TelType::class,['label'=>'Teléfono o WhatsApp'])
  ->add('contactPreference',ChoiceType::class,['label'=>'Medio de contacto preferido','choices'=>['WhatsApp'=>'whatsapp','Correo electrónico'=>'email','Llamada telefónica'=>'phone']])
  ->add('companyName',TextType::class,['label'=>'Nombre de empresa','required'=>false])
  ->add('items',CollectionType::class,['entry_type'=>QuoteRequestItemType::class,'allow_add'=>true,'allow_delete'=>true,'by_reference'=>false,'prototype'=>true,'label'=>false])
  ->add('neededAt',DateType::class,['label'=>'Fecha requerida','required'=>false,'widget'=>'single_text'])
  ->add('deliveryMethod',ChoiceType::class,['label'=>'Método de entrega','choices'=>['Recoger en sucursal'=>'pickup','Envío a domicilio'=>'shipping','Por definir'=>'undefined']])
  ->add('requiresInvoice',CheckboxType::class,['label'=>'Necesito factura','required'=>false]);}
 public function configureOptions(OptionsResolver $r):void{$r->setDefaults(['data_class'=>QuoteRequest::class,'csrf_token_id'=>'public_quote_request']);}
}
