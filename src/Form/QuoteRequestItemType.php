<?php
namespace App\Form;
use App\Application\Quotations\PublicQuotationRequestItemData;
use App\Form\Admin\Quotations\QuotationItemType;
use Symfony\Component\Form\{AbstractType,FormBuilderInterface};
use Symfony\Component\Form\Extension\Core\Type\{FileType,TextareaType};
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
final class QuoteRequestItemType extends AbstractType
{
 public function getParent():string{return QuotationItemType::class;}
 public function buildForm(FormBuilderInterface $b,array $o):void{$b->add('attachment',FileType::class,['mapped'=>false,'required'=>false,'label'=>'Archivo','constraints'=>[new File(maxSize:'20M',extensions:['pdf','ai','eps','svg','jpg','jpeg','png','tif','tiff','zip'])],'attr'=>['accept'=>'.pdf,.ai,.eps,.svg,.jpg,.jpeg,.png,.tif,.tiff,.zip','data-action'=>'change->ui--public-quote#previewFile']])->add('notes',TextareaType::class,['required'=>false,'label'=>'Indicaciones adicionales','attr'=>['rows'=>3]]);}
 public function configureOptions(OptionsResolver $r):void{$r->setDefaults(['data_class'=>PublicQuotationRequestItemData::class]);}
}
