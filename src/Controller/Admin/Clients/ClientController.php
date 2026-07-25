<?php

namespace App\Controller\Admin\Clients;

use App\Application\Clients\ClientData;
use App\Application\Clients\ClientManager;
use App\Entity\Clients\Client;
use App\Entity\Users\User;
use App\Form\Admin\Clients\ClientType;
use App\Repository\Clients\ClientRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
    public function new(Request $request, ClientManager $clientManager): Response
    {
        $this->denyAccessUnlessGranted('clients.create');

        $data = new ClientData();
        $form = $this->createForm(ClientType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $clientManager->create($data, $this->getActor());

            $this->addFlash('success', 'Cliente registrado correctamente.');

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
    ): Response {
        $this->denyAccessUnlessGranted('clients.update');

        $data = new ClientData();
        $data->businessName = $client->getBusinessName();
        $data->taxId = $client->getTaxId();
        $data->email = $client->getEmail();
        $data->phone = $client->getPhone();
        $data->notes = $client->getNotes();

        $form = $this->createForm(ClientType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $clientManager->update($client, $data, $this->getActor());

            $this->addFlash('success', 'Cliente actualizado correctamente.');

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
}