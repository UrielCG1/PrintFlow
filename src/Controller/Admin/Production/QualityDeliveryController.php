<?php

declare(strict_types=1);

namespace App\Controller\Admin\Production;

use App\Application\Production\ProductionExecutionCommand;
use App\Application\Production\ProductionQualityDeliveryManager;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Production\ProductionOperationExecution;
use App\Entity\Users\User;
use App\Enum\Orders\ProductionOrderStatus;
use App\Enum\Orders\ServiceOrderDeliveryStatus;
use App\Enum\Orders\ServiceOrderFulfillmentMethod;
use App\Enum\Production\ProductionOperationExecutionStatus;
use App\Repository\Orders\ServiceOrderRepository;
use App\Repository\Orders\ServiceOrderTraceabilityEventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/produccion', name: 'admin_production_quality_delivery_')]
final class QualityDeliveryController extends AbstractController
{
    #[Route('/calidad', name: 'quality_index', methods: ['GET'])]
    public function qualityIndex(ServiceOrderRepository $orders): Response
    {
        $this->denyAccessUnlessGranted('quality.review');
        $pending = $orders->findRecentForQualityReview();

        $timezone = new \DateTimeZone('America/Mexico_City');
        $today = new \DateTimeImmutable('today', $timezone);
        $summary = [
            'pending' => count($pending),
            'due_today' => 0,
            'incidents' => 0,
        ];
        $qualityRows = [];

        foreach ($pending as $order) {
            $progress = $this->qualityProgress($order);
            $commitment = $order->getCommitmentDate()?->setTimezone($timezone);
            $commitmentState = $commitment === null
                ? 'unassigned'
                : ($commitment->format('Y-m-d') < $today->format('Y-m-d')
                    ? 'overdue'
                    : ($commitment->format('Y-m-d') === $today->format('Y-m-d') ? 'today' : 'future'));
            $hasIncident = $progress['incidents'] > 0;
            $risk = $hasIncident
                ? 'incident'
                : ($commitmentState === 'today' ? 'today' : ($commitmentState === 'overdue' ? 'overdue' : 'ready'));

            if ($commitmentState === 'today') {
                ++$summary['due_today'];
            }
            if ($hasIncident) {
                ++$summary['incidents'];
            }

            $qualityRows[] = [
                'order' => $order,
                'progress' => $progress,
                'commitmentState' => $commitmentState,
                'risk' => $risk,
            ];
        }

        return $this->render('admin/quality_delivery/quality_index.html.twig', [
            'orders' => $pending,
            'qualityRows' => $qualityRows,
            'qualitySummary' => $summary,
        ]);
    }

    #[Route('/calidad/ordenes/{id}', name: 'quality_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function qualityShow(ServiceOrder $order, ServiceOrderTraceabilityEventRepository $events): Response
    {
        $this->denyAccessUnlessGranted('quality.review');
        $qualityProgress = $this->qualityProgress($order);

        return $this->render('admin/quality_delivery/quality_show.html.twig', [
            'order' => $order,
            'events' => $events->findRecentForServiceOrder($order),
            'canRelease' => $order->getProductionStatus() === ProductionOrderStatus::WAITING_QUALITY
                && $qualityProgress['operations'] > 0
                && $qualityProgress['incidents'] === 0,
            'qualityProgress' => $qualityProgress,
            'commandId' => bin2hex(random_bytes(24)),
            'releaseCommandId' => bin2hex(random_bytes(24)),
        ]);
    }

    #[Route('/calidad/ordenes/{id}/liberar', name: 'release', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function release(Request $request, ServiceOrder $order, ProductionQualityDeliveryManager $manager): Response
    {
        $this->denyAccessUnlessGranted('quality.release');
        $this->assertCsrf($request, 'quality-release-'.$order->getId());
        try {
            $manager->releaseProduction($order, $this->authenticatedUser(), $this->commandFromRequest($request));
            $this->addFlash('success', 'Calidad liberó la producción. La modalidad de entrega conserva su confirmación por Ventas.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_production_quality_delivery_quality_show', ['id' => $order->getId()]);
    }

    #[Route('/calidad/ordenes/{id}/partidas/{item}/operaciones/{plan}/intentos/{execution}/devolver', name: 'return_for_rework', requirements: ['id' => '\\d+', 'item' => '\\d+', 'plan' => '\\d+', 'execution' => '\\d+'], methods: ['POST'])]
    public function returnForRework(
        Request $request,
        ServiceOrder $order,
        ServiceOrderItem $item,
        ServiceOrderOperationPlan $plan,
        ProductionOperationExecution $execution,
        ProductionQualityDeliveryManager $manager,
    ): Response {
        $this->denyAccessUnlessGranted('quality.return');
        $this->assertCsrf($request, 'quality-return-'.$order->getId().'-'.$item->getId().'-'.$plan->getId().'-'.$execution->getId());
        try {
            $manager->returnOperationForRework($order, $item, $plan, $execution, $this->authenticatedUser(), $this->commandFromRequest($request));
            $this->addFlash('success', 'Calidad devolvió la operación a Producción y vinculó el intento para retrabajo.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_production_quality_delivery_quality_show', ['id' => $order->getId()]);
    }

    #[Route('/entregas', name: 'delivery_index', methods: ['GET'])]
    public function deliveryIndex(ServiceOrderRepository $orders): Response
    {
        $this->assertDeliveryAccess();
        $pending = array_values(array_filter(
            $orders->findRecentForAdministration(),
            static fn (ServiceOrder $order): bool => $order->getProductionStatus() === ProductionOrderStatus::PRODUCTION_COMPLETED
                && $order->getDeliveryStatus() !== ServiceOrderDeliveryStatus::DELIVERED,
        ));
        return $this->render('admin/quality_delivery/delivery_index.html.twig', ['orders' => $pending]);
    }

    #[Route('/entregas/ordenes/{id}', name: 'delivery_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function deliveryShow(ServiceOrder $order, ServiceOrderTraceabilityEventRepository $events): Response
    {
        $this->assertDeliveryAccess();
        return $this->render('admin/quality_delivery/delivery_show.html.twig', [
            'order' => $order,
            'events' => $events->findRecentForServiceOrder($order),
            'canSetMethod' => $this->isGranted('delivery.confirm'),
            'canShip' => $this->isGranted('delivery.ship'),
            'canConfirm' => $this->isGranted('delivery.confirm'),
            'methodCommandId' => bin2hex(random_bytes(24)),
            'shipCommandId' => bin2hex(random_bytes(24)),
            'confirmCommandId' => bin2hex(random_bytes(24)),
        ]);
    }

    #[Route('/entregas/ordenes/{id}/modalidad', name: 'set_fulfillment', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function setFulfillment(Request $request, ServiceOrder $order, ProductionQualityDeliveryManager $manager): Response
    {
        $this->denyAccessUnlessGranted('delivery.confirm');
        $this->assertCsrf($request, 'delivery-method-'.$order->getId());
        try {
            $method = ServiceOrderFulfillmentMethod::tryFrom($request->request->getString('fulfillment_method'));
            if (!$method instanceof ServiceOrderFulfillmentMethod) {
                throw new \InvalidArgumentException('Selecciona Recoger en sucursal o Envío a domicilio.');
            }
            $manager->setFulfillmentMethod($order, $method, $this->authenticatedUser(), $this->commandFromRequest($request));
            $this->addFlash('success', 'Ventas confirmó la modalidad y la orden quedó lista para el siguiente paso de entrega.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_production_quality_delivery_delivery_show', ['id' => $order->getId()]);
    }

    #[Route('/entregas/ordenes/{id}/enviar', name: 'ship', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function ship(Request $request, ServiceOrder $order, ProductionQualityDeliveryManager $manager): Response
    {
        $this->denyAccessUnlessGranted('delivery.ship');
        $this->assertCsrf($request, 'delivery-ship-'.$order->getId());
        try {
            $manager->markShipped(
                $order,
                $this->authenticatedUser(),
                $this->commandFromRequest($request),
                $request->request->getString('carrier'),
                $request->request->getString('tracking_code') ?: null,
            );
            $this->addFlash('success', 'El envío quedó registrado. Ventas confirmará la entrega al cliente cuando se complete.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_production_quality_delivery_delivery_show', ['id' => $order->getId()]);
    }

    #[Route('/entregas/ordenes/{id}/confirmar', name: 'confirm_delivery', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function confirmDelivery(Request $request, ServiceOrder $order, ProductionQualityDeliveryManager $manager): Response
    {
        $this->denyAccessUnlessGranted('delivery.confirm');
        $this->assertCsrf($request, 'delivery-confirm-'.$order->getId());
        try {
            $manager->confirmDelivered(
                $order,
                $this->authenticatedUser(),
                $this->commandFromRequest($request),
                $request->request->getString('recipient') ?: null,
            );
            $this->addFlash('success', 'La entrega quedó confirmada por Ventas.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_production_quality_delivery_delivery_show', ['id' => $order->getId()]);
    }

    /** @return array{operations: int, completed: int, incidents: int, blocked: int, open: int, percent: int} */
    private function qualityProgress(ServiceOrder $order): array
    {
        $operations = 0;
        $completed = 0;
        $incidents = 0;
        $blocked = 0;
        $open = 0;

        foreach ($order->getItems() as $item) {
            foreach ($item->getActiveOperationPlans() as $plan) {
                ++$operations;
                $isBlocked = $plan->getBlockedReason() !== null;
                $isOpen = $plan->hasOpenExecution();
                $isCompleted = $plan->hasCompletedLatestExecution();

                if ($isBlocked) {
                    ++$blocked;
                }
                if ($isOpen) {
                    ++$open;
                }
                if ($isCompleted && !$isBlocked && !$isOpen) {
                    ++$completed;
                } else {
                    ++$incidents;
                }
            }
        }

        return [
            'operations' => $operations,
            'completed' => $completed,
            'incidents' => $incidents + ($operations === 0 ? 1 : 0),
            'blocked' => $blocked,
            'open' => $open,
            'percent' => $operations > 0 ? (int) round(($completed / $operations) * 100) : 0,
        ];
    }

    private function assertDeliveryAccess(): void
    {
        if (!$this->isGranted('delivery.confirm') && !$this->isGranted('delivery.ship') && !$this->isGranted('quality.release')) {
            throw $this->createAccessDeniedException();
        }
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }
    }

    private function authenticatedUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        return $user;
    }

    private function commandFromRequest(Request $request): ProductionExecutionCommand
    {
        $version = filter_var($request->request->getString('expected_order_version'), FILTER_VALIDATE_INT);
        if ($version === false) {
            throw new \InvalidArgumentException('La versión de la orden no es válida. Actualiza la pantalla.');
        }
        return new ProductionExecutionCommand(
            $request->request->getString('command_id'),
            $version,
            $request->request->getString('note') ?: null,
        );
    }
}
