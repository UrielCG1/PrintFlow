<?php

declare(strict_types=1);

namespace App\Controller\Admin\Orders;

use App\Application\Orders\ServiceOrderManager;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Quotations\Quotation;
use App\Entity\Users\User;
use App\Repository\Orders\ServiceOrderRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/ordenes-servicio', name: 'admin_service_orders_')]
final class ServiceOrderController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(ServiceOrderRepository $serviceOrderRepository): Response
    {
        $this->denyAccessUnlessGranted('service_orders.view');

        return $this->render('admin/service_orders/index.html.twig', [
            'serviceOrders' => $serviceOrderRepository->findRecentForAdministration(),
        ]);
    }

    #[Route('/desde-cotizacion/{id}', name: 'create_from_quotation', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function createFromQuotation(
        Request $request,
        Quotation $quotation,
        ServiceOrderManager $serviceOrderManager,
        ServiceOrderRepository $serviceOrderRepository,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.create_from_quotation');

        if (!$this->isCsrfTokenValid(
            'service-order-create-from-quotation-'.$quotation->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('El token de seguridad para crear la orden no es válido.');
        }

        try {
            $serviceOrder = $serviceOrderManager->createFromAcceptedQuotation(
                $quotation,
                $this->authenticatedUser(),
            );

            $this->addFlash('success', sprintf('La orden de servicio %s se creó correctamente.', $serviceOrder->getFolio()));

            return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
        } catch (\DomainException $exception) {
            $this->addFlash('warning', $exception->getMessage());
        } catch (UniqueConstraintViolationException) {
            $existingOrder = $serviceOrderRepository->findOneBySourceQuotation($quotation);

            $this->addFlash('warning', 'Esta cotización ya fue convertida en una orden de servicio.');

            if ($existingOrder !== null) {
                return $this->redirectToRoute('admin_service_orders_show', ['id' => $existingOrder->getId()]);
            }
        }

        return $this->redirectToRoute('admin_quotations_show', ['id' => $quotation->getId()]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(ServiceOrder $serviceOrder): Response
    {
        $this->denyAccessUnlessGranted('service_orders.view');

        return $this->render('admin/service_orders/show.html.twig', [
            'serviceOrder' => $serviceOrder,
        ]);
    }

    private function authenticatedUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
