<?php

namespace App\Controller\Admin\Suppliers;

use App\Application\Suppliers\{SupplierData,SupplierBranchData,SupplierInlineContactData};
use App\Application\Clients\{ClientBranchAddressData,ClientPhoneData};
use App\Application\Suppliers\SupplierManager;
use App\Entity\Suppliers\{Supplier,SupplierAddress,SupplierBranch,SupplierContact,SupplierPhone};
use App\Entity\Common\Phone;
use App\Entity\Users\User;
use App\Form\Admin\Suppliers\SupplierType;
use App\Repository\Suppliers\SupplierRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/admin/proveedores', name: 'admin_suppliers_')]
final class SupplierController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, SupplierRepository $supplierRepository): Response
    {
        $this->denyAccessUnlessGranted('suppliers.view');

        $status = $request->query->getString('status', 'active');

        if (!in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'active';
        }

        $isActive = match ($status) {
            'active' => true,
            'inactive' => false,
            default => null,
        };

        return $this->render('admin/suppliers/index.html.twig', [
            'page' => $supplierRepository->paginateForAdministration(
                search: $request->query->getString('q'),
                isActive: $isActive,
                page: $request->query->getInt('page', 1),
            ),
            'search' => $request->query->getString('q'),
            'status' => $status,
        ]);
    }

    #[Route('/nuevo', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, SupplierManager $supplierManager): Response
    {
        $this->denyAccessUnlessGranted('suppliers.create');

        $data = new SupplierData();
        $data->phones[]=new ClientPhoneData();$branch=new SupplierBranchData();$branch->addresses[]=new ClientBranchAddressData();$branch->phones[]=new ClientPhoneData();$contact=new SupplierInlineContactData();$contact->phones[]=new ClientPhoneData();$branch->contacts[]=$contact;$data->branches[]=$branch;
        $form = $this->createForm(SupplierType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $supplierManager->create($data, $this->getActor());
            } catch (UniqueConstraintViolationException) {
                $form->get('code')->addError(
                    new FormError('El código o RFC ya está registrado para otro proveedor.'),
                );

                return $this->render('admin/suppliers/form.html.twig', [
                    'form' => $form,
                    'supplier' => null,
                    'pageTitle' => 'Nuevo proveedor',
                ]);
            }

            $this->addFlash('success', 'Proveedor registrado correctamente.');

            return $this->redirectToRoute('admin_suppliers_index');
        }

        return $this->render('admin/suppliers/form.html.twig', [
            'form' => $form,
            'supplier' => null,
            'pageTitle' => 'Nuevo proveedor',
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        Request $request,
        Supplier $supplier,
        SupplierManager $supplierManager,
        EntityManagerInterface $em,
    ): Response {
        $this->denyAccessUnlessGranted('suppliers.update');

        $data = new SupplierData();
        $data->id = $supplier->getId();
        $data->code = $supplier->getCode();
        $data->businessName = $supplier->getBusinessName();
        $data->legalName = $supplier->getLegalName();
        $data->taxId = $supplier->getTaxId();
        $data->taxRegimeCode=$supplier->getTaxRegimeCode();$data->billingEmail=$supplier->getBillingEmail();$data->defaultCfdiUseCode=$supplier->getDefaultCfdiUseCode();
        $data->businessActivity = $supplier->getBusinessActivity();
        $data->website = $supplier->getWebsite();
        $data->email = $supplier->getEmail();
        $data->phone = $supplier->getPhone();
        $data->notes = $supplier->getNotes();
        $this->hydrateStructuredData($supplier,$data,$em);

        $form = $this->createForm(SupplierType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $supplierManager->update($supplier, $data, $this->getActor());
            } catch (UniqueConstraintViolationException) {
                $form->get('code')->addError(
                    new FormError('El código o RFC ya está registrado para otro proveedor.'),
                );

                return $this->render('admin/suppliers/form.html.twig', [
                    'form' => $form,
                    'supplier' => $supplier,
                    'pageTitle' => 'Editar proveedor',
                ]);
            }

            $this->addFlash('success', 'Proveedor actualizado correctamente.');

            return $this->redirectToRoute('admin_suppliers_index');
        }

        return $this->render('admin/suppliers/form.html.twig', [
            'form' => $form,
            'supplier' => $supplier,
            'pageTitle' => 'Editar proveedor',
        ]);
    }

    #[Route('/{id}/estado', name: 'status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function status(
        Request $request,
        Supplier $supplier,
        SupplierManager $supplierManager,
    ): Response {
        $this->denyAccessUnlessGranted('suppliers.toggle_status');

        if (!$this->isCsrfTokenValid(
            'supplier_status_'.$supplier->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $supplierManager->setActive(
                $supplier,
                !$supplier->isActive(),
                $this->getActor(),
            );

            $this->addFlash(
                'success',
                $supplier->isActive()
                    ? 'Proveedor reactivado correctamente.'
                    : 'Proveedor desactivado correctamente.',
            );
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_suppliers_index', $request->query->all());
    }

    private function getActor(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
    private function hydrateStructuredData(Supplier $supplier,SupplierData $data,EntityManagerInterface $em):void
    {
        foreach($em->getRepository(SupplierPhone::class)->findBy(['supplier'=>$supplier,'branch'=>null,'isActive'=>true]) as $a){$data->phones[]=$this->phoneData($a->getId(),$a->getPhone(),$a->getLabel(),$a->isPrimary());}
        foreach($em->getRepository(SupplierBranch::class)->findBy(['supplier'=>$supplier,'isActive'=>true],['isMain'=>'DESC','name'=>'ASC']) as $branch){$bd=new SupplierBranchData();$bd->id=$branch->getId();$bd->code=$branch->getCode();$bd->name=$branch->getName();$bd->email=$branch->getEmail();$bd->notes=$branch->getNotes();$bd->isMain=$branch->isMain();foreach($em->getRepository(SupplierAddress::class)->findBy(['supplier'=>$supplier,'branch'=>$branch,'isActive'=>true]) as $a){$address=$a->getAddress();$d=new ClientBranchAddressData();$d->id=$a->getId();$d->type=$a->getAddressType();$d->deliveryZone=$a->getDeliveryZone();$d->deliveryCost=$a->getDeliveryCost()!==null?(float)$a->getDeliveryCost():null;$d->street=$address->getStreet();$d->exteriorNumber=$address->getExteriorNumber();$d->interiorNumber=$address->getInteriorNumber();$d->neighborhood=$address->getNeighborhood();$d->postalCode=$address->getPostalCode();$d->city=$address->getCity();$d->state=$address->getState();$d->countryCode=$address->getCountryCode();$d->notes=$address->getNotes();$d->isDefault=$a->isDefault();$bd->addresses[]=$d;}foreach($em->getRepository(SupplierPhone::class)->findBy(['supplier'=>$supplier,'branch'=>$branch,'isActive'=>true]) as $a){$bd->phones[]=$this->phoneData($a->getId(),$a->getPhone(),$a->getLabel(),$a->isPrimary());}foreach($em->getRepository(SupplierContact::class)->findBy(['supplier'=>$supplier,'branch'=>$branch,'isActive'=>true]) as $a){$person=$a->getContact();$d=new SupplierInlineContactData();$d->id=$a->getId();$d->firstName=$person->getFirstName();$d->lastName=$person->getLastName();$d->personalEmail=$person->getPersonalEmail();$d->businessEmail=$a->getBusinessEmail();$d->birthDate=$person->getBirthDate();$d->department=$a->getDepartment();$d->position=$a->getPosition();$d->workDays=$person->getWorkDays();$d->workHours=$person->getWorkHours();$d->notes=$a->getNotes()??$person->getNotes();$d->isPrimary=$a->isPrimary();$d->canSellProducts=$a->canSellProducts();foreach($person->getPhones() as $pa){if($pa->isActive())$d->phones[]=$this->phoneData($pa->getId(),$pa->getPhone(),$pa->getLabel(),$pa->isPrimary());}$bd->contacts[]=$d;}$data->branches[]=$bd;}
    }
    private function phoneData(?int $id,Phone $phone,?string $label,bool $primary):ClientPhoneData{$d=new ClientPhoneData();$d->id=$id;$d->type=$phone->getPhoneType();$d->countryCode=$phone->getCountryCode();$d->areaCode=$phone->getAreaCode();$d->number=$phone->getNumber();$d->extension=$phone->getExtension();$d->notes=$phone->getNotes();$d->label=$label;$d->isPrimary=$primary;return $d;}
}
