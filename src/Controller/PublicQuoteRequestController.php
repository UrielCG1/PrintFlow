<?php
namespace App\Controller;

use App\Entity\Clients\ClientAddress;
use App\Entity\Clients\ClientContact;
use App\Entity\Common\Address;
use App\Entity\Quotations\QuoteRequest;
use App\Entity\Quotations\QuoteRequestItem;
use App\Entity\Products\Product;
use App\Form\PublicQuoteRequestType;
use App\Service\Quotations\PublicQuoteRequestMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

final class PublicQuoteRequestController extends AbstractController
{
    #[Route('/cotizar', name: 'public_quote_request', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em, SluggerInterface $slugger, PublicQuoteRequestMailer $mailer): Response
    {
        $quote = new QuoteRequest();
        if (!$request->isMethod('POST')) { $quote->addItem(new QuoteRequestItem()); }
        $form = $this->createForm(PublicQuoteRequestType::class, $quote);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $existing = (bool) $form->get('existingCustomer')->getData();
            $contact = null;
            if ($existing) {
                $contact = $em->getRepository(ClientContact::class)->findActiveRequesterByPublicNumber((string) $quote->getCustomerNumber());
                if (!$contact) { $form->get('customerNumber')->addError(new FormError('No encontramos un contacto activo con este número.')); }
                else { $this->loadCustomer($quote, $contact); }
            }
            if ($quote->getItems()->isEmpty()) { $form->get('items')->addError(new FormError('Agrega al menos una partida.')); }
            foreach ($quote->getItems() as $index => $item) {
                $itemForm = $form->get('items')->get($index);
                $raw = $itemForm->get('characteristicsJson')->getData();
                if ($raw) { try { $item->setCharacteristics(json_decode($raw, true, 32, JSON_THROW_ON_ERROR)); } catch (\JsonException) { $itemForm->addError(new FormError('Las características de la partida no son válidas.')); } }
                if ($item->getProduct() && $item->getCategory() !== $item->getProduct()->getCategory()) { $itemForm->get('product')->addError(new FormError('El producto no pertenece a la categoría seleccionada.')); }
            }
            if ($form->isValid()) {
                if ($contact && $quote->getDeliveryMethod() === 'shipping') {
                    foreach ($em->getRepository(ClientAddress::class)->findForClient($contact->getClient()) as $address) {
                        if ($address->isActive() && $address->isDeliveryAddress()) { $quote->setDeliveryAddress($address)->setDeliveryAddressSnapshot($this->addressData($address->getAddress())); break; }
                    }
                }
                $first = $quote->getItems()->first();
                $quote->setProductType($first->getProduct()?->getName() ?? 'Producto')->setQuantity($first->getQuantity())->setWidth($first->getWidth())->setHeight($first->getHeight())->setMeasurementUnit($first->getMeasurementUnit()?->getCode())->setMaterial($first->getMaterial())->setPrintSides($first->getPrintSides())->setFinishes($first->getFinishes())->setNotes($first->getNotes());
                $uploadDirectory = $this->getParameter('kernel.project_dir').'/public/uploads/quote-requests';
                if (!is_dir($uploadDirectory)) { mkdir($uploadDirectory, 0775, true); }
                foreach ($form->get('items') as $index => $itemForm) {
                    $file = $itemForm->get('attachment')->getData();
                    if (!$file) { continue; }
                    $name = $slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'-'.bin2hex(random_bytes(6)).'.'.($file->guessExtension() ?: 'bin');
                    $file->move($uploadDirectory, $name);
                    $quote->getItems()->get($index)->setAttachmentPath('uploads/quote-requests/'.$name)->setAttachmentOriginalName($file->getClientOriginalName());
                }
                $quote->setDesignStatus($first->getAttachmentOriginalName() ? 'ready' : 'no_file')->setFolio(sprintf('SOL-%s-%s', (new \DateTimeImmutable())->format('Ymd'), strtoupper(bin2hex(random_bytes(3)))))->setStatus('sent');
                $em->persist($quote); $em->flush(); $mailer->send($quote);
                $this->addFlash('success', 'Tu solicitud fue confirmada y enviamos el PDF demostrativo a tu correo.');
                return $this->redirectToRoute('public_quote_request');
            }
        }
        return $this->render('public_quote_request/index.html.twig', ['form' => $form]);
    }

    #[Route('/cotizar/cliente/{number}', name: 'public_quote_customer', requirements: ['number' => '[A-Z0-9_-]+'], methods: ['GET'])]
    public function customer(string $number, EntityManagerInterface $em): JsonResponse
    {
        $contact = $em->getRepository(ClientContact::class)->findActiveRequesterByPublicNumber($number);
        if (!$contact) { return $this->json(['message' => 'Cliente no encontrado.'], 404); }
        $hasDeliveryAddress = false;
        foreach ($em->getRepository(ClientAddress::class)->findForClient($contact->getClient()) as $address) {
            if ($address->isActive() && $address->isDeliveryAddress()) { $hasDeliveryAddress = true; break; }
        }
        $email = $contact->getEmail() ?: $contact->getContact()->getPersonalEmail();
        return $this->json(['name' => $this->maskName($contact->getFullName()), 'email' => $this->maskEmail($email), 'phone' => $this->maskPhone($contact->getPhone()), 'company' => $this->maskName($contact->getClient()->getBusinessName()), 'hasDeliveryAddress' => $hasDeliveryAddress]);
    }

    #[Route('/cotizar/productos/{categoryId}', name: 'public_quote_products', requirements: ['categoryId' => '[0-9A-Z_]+'], methods: ['GET'])]
    public function products(string $categoryId, EntityManagerInterface $em): JsonResponse
    {
        if (!ctype_digit($categoryId)) { return $this->json(['message' => 'Categoría inválida.'], 400); }
        $products = $em->getRepository(Product::class)->createQueryBuilder('product')
            ->innerJoin('product.category', 'category')
            ->andWhere('category.id = :categoryId')
            ->andWhere('category.isActive = :active')
            ->andWhere('product.isActive = :active')
            ->setParameter('categoryId', (int) $categoryId)
            ->setParameter('active', true)
            ->orderBy('product.name', 'ASC')
            ->getQuery()->getResult();

        return $this->json(array_map(static fn(Product $product) => [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'schema' => $product->getConfigurationSchema() ?? [],
        ], $products));
    }

    private function loadCustomer(QuoteRequest $q, ClientContact $c): void
    {
        $q->setClientContact($c)->setClientBranch($c->getBranch())->setFullName($c->getFullName())->setEmail($c->getEmail() ?: $c->getContact()->getPersonalEmail() ?: 'sin-correo@example.invalid')->setPhone($c->getPhone() ?: 'Sin teléfono')->setCompanyName($c->getClient()->getBusinessName())->setCustomerSnapshot(['contactId' => $c->getId(), 'businessName' => $c->getClient()->getBusinessName(), 'branch' => $c->getBranch()?->getName(), 'fullName' => $c->getFullName(), 'email' => $c->getEmail() ?: $c->getContact()->getPersonalEmail(), 'phone' => $c->getPhone()]);
    }
    private function addressData(Address $a): array { return ['street'=>$a->getStreet(),'exteriorNumber'=>$a->getExteriorNumber(),'interiorNumber'=>$a->getInteriorNumber(),'neighborhood'=>$a->getNeighborhood(),'postalCode'=>$a->getPostalCode(),'city'=>$a->getCity(),'state'=>$a->getState(),'countryCode'=>$a->getCountryCode(),'notes'=>$a->getNotes()]; }
    private function maskName(?string $value): string { return implode(' ', array_map(fn(string $part) => mb_substr($part, 0, 1).str_repeat('*', max(2, min(5, mb_strlen($part)-1))), preg_split('/\s+/', trim((string) $value)) ?: [])); }
    private function maskEmail(?string $value): string { if (!$value || !str_contains($value, '@')) { return 'No registrado'; } [$local,$domain]=explode('@',$value,2); $parts=explode('.',$domain); $host=array_shift($parts); return mb_substr($local,0,1).'***@'.mb_substr($host,0,1).'***'.($parts?'.'.end($parts):''); }
    private function maskPhone(?string $value): string { $digits=preg_replace('/\D+/','',(string)$value); return $digits ? str_repeat('*',max(4,strlen($digits)-4)).substr($digits,-4) : 'No registrado'; }
}
