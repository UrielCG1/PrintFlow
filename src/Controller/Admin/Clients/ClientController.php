<?php

namespace App\Controller\Admin\Clients;

use App\Application\Clients\{ClientData, ClientPhoneData, ClientBranchData, ClientBranchAddressData, ClientInlineContactData};
use App\Application\Clients\ClientManager;
use App\Entity\Clients\{Client, ClientBranch, ClientBranchAddress, ClientBranchPhone, ClientContact};
use App\Entity\Common\Phone;
use App\Entity\Users\User;
use App\Form\Admin\Clients\ClientType;
use App\Repository\Clients\ClientRepository;
use App\Service\Clients\ClientContactEmailVerifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\FormError;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/admin/clientes', name: 'admin_clients_')]
final class ClientController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, ClientRepository $clientRepository): Response
    {
        $this->denyAccessUnlessGranted('clients.view');

        $status = $request->query->getString('status', 'active');
        $isActive = match ($status) {
            'active' => true,
            'inactive' => false,
            default => null,
        };

        return $this->render('admin/clients/index.html.twig', [
            'page' => $clientRepository->paginateForAdministration(
                search: $request->query->getString('q'),
                isActive: $isActive,
                page: $request->query->getInt('page', 1),
            ),
            'search' => $request->query->getString('q'),
            'status' => $status,
        ]);
    }

    #[Route('/nuevo', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, ClientManager $clientManager, ClientContactEmailVerifier $emailVerifier): Response
    {
        $this->denyAccessUnlessGranted('clients.create');

        $data = new ClientData();
        $data->phones[] = new ClientPhoneData();
        $branch = new ClientBranchData();
        $branch->addresses[] = new ClientBranchAddressData();
        $branch->phones[] = new ClientPhoneData();
        $contact = new ClientInlineContactData(); $contact->phones[] = new ClientPhoneData(); $branch->contacts[] = $contact;
        $data->branches[] = $branch;
        $form = $this->createForm(ClientType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $client = $clientManager->create($data, $this->getActor());
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
                return $this->render('admin/clients/form.html.twig', ['form'=>$form,'client'=>null,'pageTitle'=>'Nuevo cliente']);
            }

            try { $emailVerifier->sendPendingForClient($client); $this->addFlash('success', 'Cliente registrado correctamente. Enviamos la confirmación a sus contactos.'); }
            catch (\Throwable) { $this->addFlash('warning', 'El cliente fue registrado, pero no fue posible enviar una confirmación de correo.'); }

            return $this->redirectToRoute('admin_clients_index');
        }

        return $this->render('admin/clients/form.html.twig', [
            'form' => $form,
            'client' => null,
            'pageTitle' => 'Nuevo cliente',
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Client $client,
        ClientManager $clientManager,
        ClientContactEmailVerifier $emailVerifier,
        EntityManagerInterface $em,
    ): Response {
        $this->denyAccessUnlessGranted('clients.update');

        $data = new ClientData();
        $data->clientType = $client->getClientType();
        $data->businessName = $client->getBusinessName();
        $data->taxId = $client->getTaxId();
        $data->legalName = $client->getLegalName();
        $data->businessActivity = $client->getBusinessActivity();
        $data->website = $client->getWebsite();
        $data->birthDate = $client->getBirthDate();
        $data->taxRegimeCode = $client->getTaxRegimeCode();
        $data->billingEmail = $client->getBillingEmail();
        $data->defaultCfdiUseCode = $client->getDefaultCfdiUseCode();
        $data->category = $client->getCategory();
        $data->email = $client->getEmail();
        $data->phone = $client->getPhone();
        $data->notes = $client->getNotes();
        $this->hydrateStructuredData($client, $data, $em);

        $form = $this->createForm(ClientType::class, $data, [
            'current_category' => $client->getCategory(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $clientManager->update($client, $data, $this->getActor());
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
                return $this->render('admin/clients/form.html.twig', ['form'=>$form,'client'=>$client,'pageTitle'=>'Editar cliente']);
            }

            try { $emailVerifier->sendPendingForClient($client); $this->addFlash('success', 'Cliente actualizado correctamente.'); }
            catch (\Throwable) { $this->addFlash('warning', 'El cliente fue actualizado, pero no fue posible enviar una confirmación de correo pendiente.'); }

            return $this->redirectToRoute('admin_clients_index');
        }

        return $this->render('admin/clients/form.html.twig', [
            'form' => $form,
            'client' => $client,
            'pageTitle' => 'Editar cliente',
        ]);
    }

    #[Route('/{id}/estado', name: 'status', methods: ['POST'])]
    public function status(
        Request $request,
        Client $client,
        ClientManager $clientManager,
    ): Response {
        $this->denyAccessUnlessGranted('clients.toggle_status');

        if (!$this->isCsrfTokenValid(
            'client_status_'.$client->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        $clientManager->setActive($client, !$client->isActive(), $this->getActor());

        $this->addFlash(
            'success',
            $client->isActive()
                ? 'Cliente reactivado correctamente.'
                : 'Cliente desactivado correctamente.',
        );

        return $this->redirectToRoute('admin_clients_index', $request->query->all());
    }

    private function getActor(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function hydrateStructuredData(Client $client, ClientData $data, EntityManagerInterface $em): void
    {
        foreach ($client->getPhones() as $assignment) { if ($assignment->isActive()) { $data->phones[] = $this->phoneData($assignment->getId(), $assignment->getPhone(), $assignment->getLabel(), $assignment->isPrimary()); } }
        foreach ($em->getRepository(ClientBranch::class)->findBy(['client' => $client, 'isActive' => true], ['isMain' => 'DESC', 'name' => 'ASC']) as $branch) {
            $bd=new ClientBranchData(); $bd->id=$branch->getId(); $bd->code=$branch->getCode(); $bd->name=$branch->getName(); $bd->category=$branch->getCategory(); $bd->email=$branch->getEmail(); $bd->notes=$branch->getNotes(); $bd->isMain=$branch->isMain();
            foreach ($em->getRepository(ClientBranchAddress::class)->findBy(['branch'=>$branch,'isActive'=>true]) as $assignment) { $a=$assignment->getAddress(); $ad=new ClientBranchAddressData(); $ad->id=$assignment->getId(); $ad->type=$assignment->getAddressType(); $ad->deliveryZone=$assignment->getDeliveryZone(); $ad->deliveryCost=$assignment->getDeliveryCost()!==null?(float)$assignment->getDeliveryCost():null; $ad->street=$a->getStreet(); $ad->exteriorNumber=$a->getExteriorNumber(); $ad->interiorNumber=$a->getInteriorNumber(); $ad->neighborhood=$a->getNeighborhood(); $ad->postalCode=$a->getPostalCode(); $ad->city=$a->getCity(); $ad->state=$a->getState(); $ad->countryCode=$a->getCountryCode(); $ad->notes=$a->getNotes(); $ad->isDefault=$assignment->isDefault(); $bd->addresses[]=$ad; }
            foreach ($em->getRepository(ClientBranchPhone::class)->findBy(['branch'=>$branch,'isActive'=>true]) as $assignment) { $bd->phones[]=$this->phoneData($assignment->getId(),$assignment->getPhone(),$assignment->getLabel(),$assignment->isPrimary()); }
            foreach ($em->getRepository(ClientContact::class)->findBy(['client'=>$client,'branch'=>$branch,'isActive'=>true]) as $assignment) { $person=$assignment->getContact(); $cd=new ClientInlineContactData(); $cd->id=$assignment->getId(); $cd->firstName=$person->getFirstName(); $cd->lastName=$person->getLastName(); $cd->personalEmail=$person->getPersonalEmail(); $cd->businessEmail=$assignment->getEmail(); $cd->birthDate=$person->getBirthDate(); $cd->department=$assignment->getDepartment(); $cd->jobTitle=$assignment->getJobTitle(); $cd->workDays=$person->getWorkDays(); $cd->workHours=$person->getWorkHours(); $cd->notes=$person->getNotes(); $cd->isPrimary=$assignment->isPrimary(); $cd->canRequestProducts=$assignment->canRequestProducts(); foreach($person->getPhones() as $pa){if($pa->isActive()){$cd->phones[]=$this->phoneData($pa->getId(),$pa->getPhone(),$pa->getLabel(),$pa->isPrimary());}} $bd->contacts[]=$cd; }
            $data->branches[]=$bd;
        }
    }

    private function phoneData(?int $id, Phone $phone, ?string $label, bool $primary): ClientPhoneData
    {
        $d=new ClientPhoneData(); $d->id=$id; $d->type=$phone->getPhoneType(); $d->countryCode=$phone->getCountryCode(); $d->areaCode=$phone->getAreaCode(); $d->number=$phone->getNumber(); $d->extension=$phone->getExtension(); $d->notes=$phone->getNotes(); $d->label=$label; $d->isPrimary=$primary; return $d;
    }
}
