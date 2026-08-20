<?php
declare(strict_types=1);
namespace App\Controller;
use App\Application\Quotations\{PublicQuotationClientResolver,PublicQuotationRequestData,PublicQuotationRequestItemData,QuotationData,QuotationItemCharacteristicsSpecificationResolver,QuotationManager};
use App\Entity\Catalog\CommercialItem;
use App\Entity\Clients\{Client,ClientAddress,ClientContact};
use App\Form\PublicQuoteRequestType;
use App\Repository\Catalog\CommercialItemCharacteristicRepository;
use App\Repository\Clients\ClientContactRepository;
use App\Service\Quotations\PublicQuoteRequestMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{JsonResponse,Request,Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
final class PublicQuoteRequestController extends AbstractController
{
 #[Route('/cotizar',name:'public_quote_request',methods:['GET','POST'])]
 public function index(Request $request,EntityManagerInterface $em,SluggerInterface $slugger,PublicQuotationClientResolver $clientResolver,QuotationManager $manager,PublicQuoteRequestMailer $mailer):Response
 {
  $data=new PublicQuotationRequestData();if(!$request->isMethod('POST'))$data->addItem(new PublicQuotationRequestItemData());
  $form=$this->createForm(PublicQuoteRequestType::class,$data);$form->handleRequest($request);$verifiedContact=null;
  if($form->isSubmitted()){
   $existing=(bool)$form->get('existingCustomer')->getData();
   if($existing){$contact=$em->getRepository(ClientContact::class)->findActiveRequesterByPublicNumber((string)$data->customerNumber);if(!$contact instanceof ClientContact){$form->get('customerNumber')->addError(new FormError('No encontramos un contacto activo con este número.'));}else{$verifiedContact=$contact;$data->fullName=$contact->getFullName();$data->email=$contact->getEmail()?:$contact->getContact()->getPersonalEmail();$data->phone=$contact->getPhone();$data->companyName=$contact->getClient()->getBusinessName();}}
   foreach($data->items as $index=>$item){$itemForm=$form->get('items')->get((string) $index);$raw=$itemForm->get('characteristicsJson')->getData();if($raw){try{$item->characteristics=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){$itemForm->addError(new FormError('Las características de la partida no son válidas.'));}}if($item->product&&$item->category?->getId()!==$item->product->getCategory()->getId())$itemForm->get('product')->addError(new FormError('El producto no pertenece a la categoría seleccionada.'));}
   if($form->isValid()){
    try{
     $customer=$clientResolver->resolve($data,$verifiedContact);$quotationData=new QuotationData();$quotationData->client=$customer->client;$quotationData->commercialContactId=(string)$customer->contact->getId();$quotationData->discountPercent=null;$quotationData->items=array_map(static fn(PublicQuotationRequestItemData $item)=>$item->toQuotationItemData(),$data->items);
     if($verifiedContact&&$data->deliveryMethod==='shipping'){foreach($em->getRepository(ClientAddress::class)->findForClient($customer->client) as $address){if($address->isActive()&&$address->isDeliveryAddress()){$quotationData->deliveryAddressId=(string)$address->getId();break;}}}
     $uploadDirectory=$this->getParameter('kernel.project_dir').'/public/uploads/quotations';if(!is_dir($uploadDirectory))mkdir($uploadDirectory,0775,true);
     foreach($form->get('items') as $index=>$itemForm){$file=$itemForm->get('attachment')->getData();if(!$file)continue;$name=$slugger->slug(pathinfo($file->getClientOriginalName(),PATHINFO_FILENAME)).'-'.bin2hex(random_bytes(6)).'.'.($file->guessExtension()?:'bin');$file->move($uploadDirectory,$name);$data->items[$index]->attachmentPath='uploads/quotations/'.$name;$data->items[$index]->attachmentOriginalName=$file->getClientOriginalName();}
     $quotation=$manager->createPublic($quotationData,$data);$mailer->send($quotation);$this->addFlash('success','Tu solicitud fue confirmada y enviamos la cotización calculada a tu correo.');return $this->redirectToRoute('public_quote_request');
    }catch(\DomainException|\InvalidArgumentException $exception){$form->addError(new FormError($exception->getMessage()));}
    }else{
     $messages=[];foreach($form->getErrors(true) as $error){$messages[]=$error->getMessage();}
     $form->addError(new FormError('No pudimos enviar la solicitud. Revisa los campos marcados: '.implode(' ',array_unique($messages))));
    }
  }
  return $this->render('public_quote_request/index.html.twig',['form'=>$form]);
 }
 #[Route('/cotizar/cliente/{number}',name:'public_quote_customer',requirements:['number'=>'[A-Z0-9_-]+'],methods:['GET'])]
 public function customer(string $number,ClientContactRepository $contacts,EntityManagerInterface $em):JsonResponse
 {
  $contact=$contacts->findActiveRequesterByPublicNumber($number);if(!$contact)return $this->json(['message'=>'Cliente no encontrado.'],404);$hasDelivery=false;foreach($em->getRepository(ClientAddress::class)->findForClient($contact->getClient()) as $address){if($address->isActive()&&$address->isDeliveryAddress()){$hasDelivery=true;break;}}$email=$contact->getEmail()?:$contact->getContact()->getPersonalEmail();return $this->json(['name'=>$this->maskName($contact->getFullName()),'email'=>$this->maskEmail($email),'phone'=>$this->maskPhone($contact->getPhone()),'company'=>$this->maskName($contact->getClient()->getBusinessName()),'hasDeliveryAddress'=>$hasDelivery]);
 }
 #[Route('/cotizar/productos/{categoryId}',name:'public_quote_products',requirements:['categoryId'=>'\d+'],methods:['GET'])]
 public function products(int $categoryId,EntityManagerInterface $em,CommercialItemCharacteristicRepository $configurations):JsonResponse
 {
  $items=$em->getRepository(CommercialItem::class)->createQueryBuilder('item')->innerJoin('item.category','category')->andWhere('category.id=:category')->andWhere('category.isActive=true')->andWhere('item.isActive=true')->setParameter('category',$categoryId)->orderBy('item.name','ASC')->getQuery()->getResult();
  return $this->json(array_map(function(CommercialItem $item)use($configurations):array{$fields=[];foreach($configurations->findForQuotationItem($item) as $configuration){$characteristic=$configuration->getCharacteristic();if(in_array($characteristic->getCode(),['FINISHED_WIDTH_CM','FINISHED_HEIGHT_CM'],true))continue;$key=QuotationItemCharacteristicsSpecificationResolver::fieldKey($characteristic);$options=[];foreach($configuration->getAllowedOptions() as $allowed)$options[]=['value'=>$allowed->getCharacteristicOption()->getCode(),'label'=>$allowed->getCharacteristicOption()->getName()];$fields[$key]=['label'=>$characteristic->getName(),'options'=>$options,'required'=>$configuration->isRequired()];}return ['id'=>$item->getId(),'name'=>$item->getName(),'schema'=>['dimensions'=>$item->getQuotationSpecificationProfile()->value==='LARGE_FORMAT','fields'=>$fields]];},$items));
 }
 private function maskName(?string $value):string{return implode(' ',array_map(fn(string $part)=>mb_substr($part,0,1).str_repeat('*',max(2,min(5,mb_strlen($part)-1))),preg_split('/\s+/',trim((string)$value))?:[]));}
 private function maskEmail(?string $value):string{if(!$value||!str_contains($value,'@'))return 'No registrado';[$local,$domain]=explode('@',$value,2);$parts=explode('.',$domain);$host=array_shift($parts);return mb_substr($local,0,1).'***@'.mb_substr($host,0,1).'***'.($parts?'.'.end($parts):'');}
 private function maskPhone(?string $value):string{$digits=preg_replace('/\D+/','',(string)$value);return $digits?str_repeat('*',max(4,strlen($digits)-4)).substr($digits,-4):'No registrado';}
}
