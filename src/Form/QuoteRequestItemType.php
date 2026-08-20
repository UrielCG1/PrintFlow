<?php
namespace App\Form;
use App\Application\Quotations\PublicQuotationRequestItemData;
use App\Entity\Catalog\{CommercialCategory,CommercialItem,MeasurementUnit};
use App\Repository\Materials\MaterialRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{ChoiceType,FileType,HiddenType,NumberType,TextareaType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
final class QuoteRequestItemType extends AbstractType
{
 public function __construct(private readonly MaterialRepository $materials){}
 public function buildForm(FormBuilderInterface $b,array $o):void
 {
  $choices=[];foreach($this->materials->findAvailableForMaterialForm() as $material){$choices[$material->getName()]=$material->getName();}
  $b
   ->add('category',EntityType::class,['class'=>CommercialCategory::class,'choice_label'=>'name','placeholder'=>'Busca una categoría','query_builder'=>fn($r)=>$r->createQueryBuilder('c')->where('c.isActive = true')->orderBy('c.displayOrder','ASC')->addOrderBy('c.name','ASC'),'attr'=>['class'=>'form-select pf-select2','data-action'=>'change->ui--public-quote#categoryChanged']])
   ->add('product',EntityType::class,['class'=>CommercialItem::class,'choice_label'=>'name','placeholder'=>'Selecciona un producto','query_builder'=>fn($r)=>$r->createQueryBuilder('p')->join('p.category','c')->where('p.isActive = true')->orderBy('p.name','ASC'),'choice_attr'=>fn(CommercialItem $p)=>['data-category'=>(string)$p->getCategory()->getId()],'attr'=>['class'=>'form-select pf-select2','data-action'=>'change->ui--public-quote#productChanged']])
   ->add('quantity',NumberType::class,['label'=>'Cantidad','scale'=>4,'attr'=>['min'=>'0.0001','step'=>'0.0001']])
   ->add('width',NumberType::class,['label'=>'Ancho','required'=>false,'scale'=>4,'attr'=>['min'=>'0.0001','step'=>'0.0001']])
   ->add('height',NumberType::class,['label'=>'Alto','required'=>false,'scale'=>4,'attr'=>['min'=>'0.0001','step'=>'0.0001']])
   ->add('measurementUnit',EntityType::class,['class'=>MeasurementUnit::class,'choice_label'=>'name','placeholder'=>'Unidad','required'=>false,'query_builder'=>fn($r)=>$r->createQueryBuilder('u')->where('u.isActive = true')->orderBy('u.displayOrder','ASC')->addOrderBy('u.name','ASC')])
   ->add('material',ChoiceType::class,['required'=>false,'placeholder'=>'Selecciona un material','choices'=>$choices])
   ->add('printSides',ChoiceType::class,['label'=>'Caras de impresión','required'=>false,'placeholder'=>'Selecciona','choices'=>['Una cara'=>'one_side','Dos caras'=>'two_sides','Por definir'=>'undefined']])
   ->add('finishes',ChoiceType::class,['required'=>false,'multiple'=>true,'expanded'=>true,'choices'=>['Laminado'=>'laminated','Corte especial'=>'special_cut','Doblado'=>'folded','Barniz'=>'varnish','Ojillos'=>'eyelets']])
   ->add('characteristicsJson',HiddenType::class,['mapped'=>false,'required'=>false])
   ->add('attachment',FileType::class,['mapped'=>false,'required'=>false,'label'=>'Archivo','constraints'=>[new File(maxSize:'20M',extensions:['pdf','ai','eps','svg','jpg','jpeg','png','tif','tiff','zip'])],'attr'=>['accept'=>'.pdf,.ai,.eps,.svg,.jpg,.jpeg,.png,.tif,.tiff,.zip','data-action'=>'change->ui--public-quote#previewFile']])
   ->add('notes',TextareaType::class,['required'=>false,'label'=>'Indicaciones adicionales','attr'=>['rows'=>3]]);
 }
 public function configureOptions(OptionsResolver $r):void{$r->setDefaults(['data_class'=>PublicQuotationRequestItemData::class]);}
}
