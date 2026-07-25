<?php

namespace App\Controller\Admin\Clients;

use App\Application\Clients\ClientContactData;
use App\Application\Clients\ClientContactManager;
use App\Entity\Clients\Client;
use App\Entity\Clients\ClientContact;
use App\Entity\Users\User;
use App\Form\Admin\Clients\ClientContactType;
use App\Repository\Clients\ClientContactRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/admin/clientes/{clientId}/contactos',
    name: 'admin_client_contacts_',
    requirements: ['clientId' => '\d+']
)]
final class ClientContactController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        #[MapEntity(id: 'clientId')] Client $client,
        ClientContactRepository $clientContactRepository,
    ): Response {
        $this->denyAccessUnlessGranted('clients.contacts.view');

        return $this->render('admin/clients/contacts/index.html.twig', [
            'client' => $client,
            'contacts' => $clientContactRepository->findForClient($client),
        ]);
    }

    #[Route('/nuevo', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        #[MapEntity(id: 'clientId')] Client $client,
        ClientContactManager $clientContactManager,
    ): Response {
        $this->denyAccessUnlessGranted('clients.contacts.create');

        if (!$client->isActive()) {
            $this->addFlash(
                'error',
                'No es posible registrar contactos en un cliente inactivo.',
            );

            return $this->redirectToRoute('admin_client_contacts_index', [
                'clientId' => $client->getId(),
            ]);
        }

        $data = new ClientContactData();
        $form = $this->createForm(ClientContactType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $clientContactManager->create($client, $data, $this->getActor());

            $this->addFlash('success', 'Contacto registrado correctamente.');

            return $this->redirectToRoute('admin_client_contacts_index', [
                'clientId' => $client->getId(),
            ]);
        }

        return $this->render('admin/clients/contacts/form.html.twig', [
            'client' => $client,
            'contact' => null,
            'form' => $form,
            'pageTitle' => 'Nuevo contacto',
        ]);
    }

    #[Route(
        '/{contactId}/editar',
        name: 'edit',
        methods: ['GET', 'POST'],
        requirements: ['contactId' => '\d+']
    )]
    public function edit(
        Request $request,
        #[MapEntity(id: 'clientId')] Client $client,
        #[MapEntity(id: 'contactId')] ClientContact $contact,
        ClientContactManager $clientContactManager,
    ): Response {
        $this->denyAccessUnlessGranted('clients.contacts.update');
        $this->ensureContactBelongsToClient($contact, $client);

        $data = new ClientContactData();
        $data->fullName = $contact->getFullName();
        $data->jobTitle = $contact->getJobTitle();
        $data->email = $contact->getEmail();
        $data->phone = $contact->getPhone();
        $data->isPrimary = $contact->isPrimary();

        $form = $this->createForm(ClientContactType::class, $data, [
            'contact_is_active' => $contact->isActive(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $clientContactManager->update($contact, $data, $this->getActor());

            $this->addFlash('success', 'Contacto actualizado correctamente.');

            return $this->redirectToRoute('admin_client_contacts_index', [
                'clientId' => $client->getId(),
            ]);
        }

        return $this->render('admin/clients/contacts/form.html.twig', [
            'client' => $client,
            'contact' => $contact,
            'form' => $form,
            'pageTitle' => 'Editar contacto',
        ]);
    }

    #[Route(
        '/{contactId}/estado',
        name: 'status',
        methods: ['POST'],
        requirements: ['contactId' => '\d+']
    )]
    public function status(
        Request $request,
        #[MapEntity(id: 'clientId')] Client $client,
        #[MapEntity(id: 'contactId')] ClientContact $contact,
        ClientContactManager $clientContactManager,
    ): Response {
        $this->denyAccessUnlessGranted('clients.contacts.toggle_status');
        $this->ensureContactBelongsToClient($contact, $client);

        if (!$this->isCsrfTokenValid(
            'client_contact_status_'.$contact->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        $clientContactManager->setActive(
            $contact,
            !$contact->isActive(),
            $this->getActor(),
        );

        $this->addFlash(
            'success',
            $contact->isActive()
                ? 'Contacto reactivado correctamente.'
                : 'Contacto desactivado correctamente.',
        );

        return $this->redirectToRoute('admin_client_contacts_index', [
            'clientId' => $client->getId(),
        ]);
    }

    private function ensureContactBelongsToClient(
        ClientContact $contact,
        Client $client,
    ): void {
        if ($contact->getClient()->getId() !== $client->getId()) {
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