<?php

namespace App\Controller\Admin\Clients;

use App\Application\Clients\ClientAddressData;
use App\Application\Clients\ClientAddressManager;
use App\Entity\Clients\Client;
use App\Entity\Clients\ClientAddress;
use App\Entity\Users\User;
use App\Form\Admin\Clients\ClientAddressType;
use App\Repository\Clients\ClientAddressRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/admin/clientes/{clientId}/direcciones',
    name: 'admin_client_addresses_',
    requirements: ['clientId' => '\d+']
)]
final class ClientAddressController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        #[MapEntity(id: 'clientId')] Client $client,
        ClientAddressRepository $clientAddressRepository,
    ): Response {
        $this->denyAccessUnlessGranted('clients.addresses.view');

        return $this->render('admin/clients/addresses/index.html.twig', [
            'client' => $client,
            'addresses' => $clientAddressRepository->findForClient($client),
        ]);
    }

    #[Route('/nueva', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        #[MapEntity(id: 'clientId')] Client $client,
        ClientAddressManager $clientAddressManager,
    ): Response {
        $this->denyAccessUnlessGranted('clients.addresses.create');

        if (!$client->isActive()) {
            $this->addFlash(
                'error',
                'No es posible registrar direcciones en un cliente inactivo.',
            );

            return $this->redirectToRoute('admin_client_addresses_index', [
                'clientId' => $client->getId(),
            ]);
        }

        $data = new ClientAddressData();
        $form = $this->createForm(ClientAddressType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $clientAddressManager->create($client, $data, $this->getActor());

            $this->addFlash('success', 'Dirección registrada correctamente.');

            return $this->redirectToRoute('admin_client_addresses_index', [
                'clientId' => $client->getId(),
            ]);
        }

        return $this->render('admin/clients/addresses/form.html.twig', [
            'client' => $client,
            'address' => null,
            'form' => $form,
            'pageTitle' => 'Nueva dirección',
        ]);
    }

    #[Route(
        '/{addressId}/editar',
        name: 'edit',
        methods: ['GET', 'POST'],
        requirements: ['addressId' => '\d+']
    )]
    public function edit(
        Request $request,
        #[MapEntity(id: 'clientId')] Client $client,
        #[MapEntity(id: 'addressId')] ClientAddress $address,
        ClientAddressManager $clientAddressManager,
    ): Response {
        $this->denyAccessUnlessGranted('clients.addresses.update');
        $this->ensureAddressBelongsToClient($address, $client);

        $data = new ClientAddressData();
        $data->label = $address->getLabel();
        $data->recipientName = $address->getRecipientName();
        $data->street = $address->getStreet();
        $data->exteriorNumber = $address->getExteriorNumber();
        $data->interiorNumber = $address->getInteriorNumber();
        $data->neighborhood = $address->getNeighborhood();
        $data->postalCode = $address->getPostalCode();
        $data->municipality = $address->getMunicipality();
        $data->state = $address->getState();
        $data->references = $address->getReferences();
        $data->isFiscalAddress = $address->isFiscalAddress();
        $data->isDeliveryAddress = $address->isDeliveryAddress();
        $data->isDefaultFiscal = $address->isDefaultFiscal();
        $data->isDefaultDelivery = $address->isDefaultDelivery();

        $form = $this->createForm(ClientAddressType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $clientAddressManager->update($address, $data, $this->getActor());

            $this->addFlash('success', 'Dirección actualizada correctamente.');

            return $this->redirectToRoute('admin_client_addresses_index', [
                'clientId' => $client->getId(),
            ]);
        }

        return $this->render('admin/clients/addresses/form.html.twig', [
            'client' => $client,
            'address' => $address,
            'form' => $form,
            'pageTitle' => 'Editar dirección',
        ]);
    }

    #[Route(
        '/{addressId}/estado',
        name: 'status',
        methods: ['POST'],
        requirements: ['addressId' => '\d+']
    )]
    public function status(
        Request $request,
        #[MapEntity(id: 'clientId')] Client $client,
        #[MapEntity(id: 'addressId')] ClientAddress $address,
        ClientAddressManager $clientAddressManager,
    ): Response {
        $this->denyAccessUnlessGranted('clients.addresses.toggle_status');
        $this->ensureAddressBelongsToClient($address, $client);

        if (!$this->isCsrfTokenValid(
            'client_address_status_'.$address->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        $clientAddressManager->setActive(
            $address,
            !$address->isActive(),
            $this->getActor(),
        );

        $this->addFlash(
            'success',
            $address->isActive()
                ? 'Dirección reactivada correctamente.'
                : 'Dirección desactivada correctamente.',
        );

        return $this->redirectToRoute('admin_client_addresses_index', [
            'clientId' => $client->getId(),
        ]);
    }

    private function ensureAddressBelongsToClient(
        ClientAddress $address,
        Client $client,
    ): void {
        if ($address->getClient()->getId() !== $client->getId()) {
            throw $this->createNotFoundException();
        }
    }

    private function getActor(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}