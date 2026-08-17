<?php
namespace App\Form;
use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Products\Product;
use App\Entity\Products\ProductCategory;
use App\Entity\Quotations\QuoteRequestItem;
use App\Repository\Materials\MaterialRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

final class QuoteRequestItemType extends AbstractType
{
    public function __construct(private readonly MaterialRepository $materials) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $materials = $this->materials->findAvailableForMaterialForm();
        $materialChoices = [];
        foreach ($materials as $material) { $materialChoices[$material->getName()] = $material->getName(); }

        $builder
            ->add('category', EntityType::class, ['class'=>ProductCategory::class,'choice_label'=>'name','placeholder'=>'Busca una categoría','query_builder'=>fn($r)=>$r->createQueryBuilder('c')->where('c.isActive = true')->orderBy('c.name','ASC'),'attr'=>['class'=>'form-select pf-select2','data-action'=>'change->ui--public-quote#categoryChanged']])
            ->add('product', EntityType::class, ['class'=>Product::class,'choice_label'=>'name','placeholder'=>'Selecciona un producto','query_builder'=>fn($r)=>$r->createQueryBuilder('p')->join('p.category','c')->where('p.isActive = true')->orderBy('p.name','ASC'),'choice_attr'=>fn(Product $p)=>['data-category'=>(string)$p->getCategory()->getId(),'data-schema'=>json_encode($p->getConfigurationSchema()??[],JSON_THROW_ON_ERROR)],'attr'=>['class'=>'form-select pf-select2','data-action'=>'change->ui--public-quote#productChanged']])
            ->add('quantity', IntegerType::class, ['label'=>'Cantidad','attr'=>['min'=>1]])
            ->add('width', NumberType::class, ['label'=>'Ancho','required'=>false,'scale'=>2,'attr'=>['min'=>'0.01','step'=>'0.01']])
            ->add('height', NumberType::class, ['label'=>'Alto','required'=>false,'scale'=>2,'attr'=>['min'=>'0.01','step'=>'0.01']])
            ->add('measurementUnit', EntityType::class, ['class'=>MeasurementUnit::class,'choice_label'=>fn(MeasurementUnit $u)=>$u->getName(),'placeholder'=>'Unidad','required'=>false,'query_builder'=>fn($r)=>$r->createQueryBuilder('u')->where('u.isActive = true')->orderBy('u.displayOrder','ASC')->addOrderBy('u.name','ASC')])
            ->add('material', ChoiceType::class, ['required'=>false,'placeholder'=>'Selecciona un material','choices'=>$materialChoices])
            ->add('printSides', ChoiceType::class, ['label'=>'Caras de impresión','required'=>false,'placeholder'=>'Selecciona','choices'=>['Una cara'=>'one_side','Dos caras'=>'two_sides','Por definir'=>'undefined']])
            ->add('finishes', ChoiceType::class, ['required'=>false,'multiple'=>true,'expanded'=>true,'choices'=>['Laminado'=>'laminated','Corte especial'=>'special_cut','Doblado'=>'folded','Barniz'=>'varnish','Ojillos'=>'eyelets']])
            ->add('characteristicsJson', HiddenType::class, ['mapped'=>false,'required'=>false])
            ->add('attachment', FileType::class, ['mapped'=>false,'required'=>false,'label'=>'Archivo','constraints'=>[new File(maxSize:'20M',extensions:['pdf','ai','eps','svg','jpg','jpeg','png','tif','tiff','zip'])],'attr'=>['accept'=>'.pdf,.ai,.eps,.svg,.jpg,.jpeg,.png,.tif,.tiff,.zip','data-action'=>'change->ui--public-quote#previewFile']])
            ->add('notes', TextareaType::class, ['required'=>false,'label'=>'Indicaciones adicionales','attr'=>['rows'=>3]]);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class'=>QuoteRequestItem::class]); }
}
