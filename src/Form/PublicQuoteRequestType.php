<?php
namespace App\Form;
use App\Application\Quotations\PublicQuotationRequestData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{CheckboxType,ChoiceType,CollectionType,DateType,EmailType,HiddenType,TelType,TextType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
final class PublicQuoteRequestType extends AbstractType
{
 public function buildForm(FormBuilderInterface $b,array $o):void{$b
  ->add('existingCustomer',CheckboxType::class,['mapped'=>false,'required'=>false,'label'=>'Ya soy cliente'])
  ->add('customerNumber',TextType::class,['required'=>false,'label'=>'Número de cliente','help'=>'Usa el número público asignado a tu contacto.','attr'=>['autocomplete'=>'off','maxlength'=>27,'placeholder'=>'CL-XXXXXXXXXXXXXXXXXXXXXXXX']])
  ->add('deliveryAddressId',HiddenType::class,['mapped'=>false,'required'=>false])
  ->add('fullName',TextType::class,['label'=>'Nombre completo'])->add('email',EmailType::class,['label'=>'Correo electrónico'])->add('phone',TelType::class,['label'=>'Teléfono o WhatsApp'])
  ->add('contactPreference',ChoiceType::class,['label'=>'Medio de contacto preferido','choices'=>['WhatsApp'=>'whatsapp','Correo electrónico'=>'email','Llamada telefónica'=>'phone']])
  ->add('companyName',TextType::class,['label'=>'Nombre de empresa','required'=>false])
  ->add('items',CollectionType::class,['entry_type'=>QuoteRequestItemType::class,'allow_add'=>true,'allow_delete'=>true,'by_reference'=>false,'prototype'=>true,'label'=>false])
  ->add('neededAt',DateType::class,['label'=>'Fecha deseada de entrega','required'=>false,'widget'=>'single_text','help'=>'La fecha es una preferencia. La fecha final se confirmará de acuerdo con la disponibilidad de producción y entrega.','constraints'=>[new \Symfony\Component\Validator\Constraints\GreaterThanOrEqual(value:'today',message:'La fecha deseada no puede ser anterior al día de hoy.')],'attr'=>['min'=>(new \DateTimeImmutable('today'))->format('Y-m-d'),'data-action'=>'change->ui--public-quote#showDateNotice']])
  ->add('deliveryMethod',ChoiceType::class,['label'=>'Método de entrega','choices'=>['Recoger en sucursal'=>'pickup','Envío a domicilio'=>'shipping','Por definir'=>'undefined']])
  ->add('requiresInvoice',CheckboxType::class,['label'=>'Necesito factura','required'=>false]);}
 public function configureOptions(OptionsResolver $r):void{$r->setDefaults(['data_class'=>PublicQuotationRequestData::class,'csrf_token_id'=>'public_quote_request']);}
}
