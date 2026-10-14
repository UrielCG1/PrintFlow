<?php

declare(strict_types=1);

namespace App\Controller\Admin\Inventory;

use App\Application\Inventory\InventoryCycleCountCommand;
use App\Application\Inventory\InventoryCycleCountManager;
use App\Application\Inventory\InventoryCycleCountPlanner;
use App\Entity\Inventory\InventoryCycleCount;
use App\Entity\Users\User;
use App\Repository\Inventory\InventoryCycleCountRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/inventario/conteos', name: 'admin_inventory_cycle_counts_')]
final class InventoryCycleCountController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        InventoryCycleCountRepository $cycleCountRepository,
        InventoryCycleCountPlanner $cycleCountPlanner,
    ): Response
    {
        $this->denyAccessUnlessGranted('inventory.view');
        $canReview = $this->isGranted('inventory.adjust_stock');
        $canCapture = $this->isGranted('inventory.count');

        return $this->render('admin/inventory/cycle_counts/index.html.twig', [
            'canCapture' => $canCapture,
            'canReview' => $canReview,
            'priorityCounts' => $canCapture ? $cycleCountPlanner->suggest() : [],
            'pendingCounts' => $canReview ? $cycleCountRepository->findPendingForReview() : [],
            'recentCounts' => $canReview
                ? $cycleCountRepository->findRecent()
                : $cycleCountRepository->findRecentForRequester($this->getActor()),
            'idempotencyKey' => bin2hex(random_bytes(16)),
        ]);
    }

    #[Route('/capturar', name: 'capture', methods: ['POST'])]
    public function capture(Request $request, InventoryCycleCountManager $cycleCountManager): Response
    {
        $this->denyAccessUnlessGranted('inventory.count');
        if (!$this->isCsrfTokenValid('inventory_cycle_count_capture', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $cycleCount = $cycleCountManager->capture(new InventoryCycleCountCommand(
                $request->request->getString('idempotency_key'),
                $request->request->getString('qr_payload'),
                $request->request->getString('counted_quantity'),
                $request->request->getString('note'),
            ), $this->getActor());
            $this->addFlash('success', sprintf(
                'Conteo de %s capturado (%s %s). Espera la revisión administrativa.',
                $cycleCount->getStockUnit()->getUnitCode(),
                $cycleCount->getCountedQuantity(),
                $cycleCount->getVariant()->getInventoryUnit()->getSymbol(),
            ));
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_inventory_cycle_counts_index');
    }

    #[Route('/{id}/confirmar', name: 'approve', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function approve(
        Request $request,
        InventoryCycleCount $cycleCount,
        InventoryCycleCountManager $cycleCountManager,
    ): Response {
        $this->denyAccessUnlessGranted('inventory.adjust_stock');
        if (!$this->isCsrfTokenValid('inventory_cycle_count_approve_'.$cycleCount->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $cycleCount = $cycleCountManager->approve($cycleCount, $request->request->getString('review_note'), $this->getActor());
            if ($cycleCount->isStale()) {
                $this->addFlash('warning', 'La unidad tuvo un movimiento después de la lectura. No se aplicó ningún ajuste; realiza un reconteo.');
            } else {
                $this->addFlash('success', 'Conteo confirmado y diferencia registrada en la bitácora de inventario.');
            }
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_inventory_cycle_counts_index');
    }

    #[Route('/{id}/rechazar', name: 'reject', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function reject(
        Request $request,
        InventoryCycleCount $cycleCount,
        InventoryCycleCountManager $cycleCountManager,
    ): Response {
        $this->denyAccessUnlessGranted('inventory.adjust_stock');
        if (!$this->isCsrfTokenValid('inventory_cycle_count_reject_'.$cycleCount->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $cycleCountManager->reject($cycleCount, $request->request->getString('review_note'), $this->getActor());
            $this->addFlash('success', 'Conteo rechazado. El responsable podrá capturar una nueva lectura.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_inventory_cycle_counts_index');
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
