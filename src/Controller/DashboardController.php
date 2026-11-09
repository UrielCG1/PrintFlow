<?php

namespace App\Controller;

use App\Application\Dashboard\DashboardQuery;
use App\Entity\Users\User;
use App\Application\Inventory\InventoryOperationalIndicatorService;
use App\Repository\Materials\MaterialVariantRepository;
use App\Repository\Inventory\InventoryCommitmentRepository;
use App\Repository\Inventory\InventoryShortageDecisionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(
        MaterialVariantRepository $variantRepository,
        InventoryCommitmentRepository $commitmentRepository,
        InventoryShortageDecisionRepository $shortageDecisionRepository,
        InventoryOperationalIndicatorService $indicatorService,
        DashboardQuery $dashboardQuery,
    ): Response
    {
        $this->denyAccessUnlessGranted('dashboard.view');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $canManageProduction = $this->isGranted('production.view') && ($this->isGranted('production.assign') || $this->isGranted('ROLE_ADMIN'));
        $dashboard = $dashboardQuery->collect(
            $user,
            $this->isGranted('service_orders.view') && ($canManageProduction || $this->isGranted('delivery.confirm') || $this->isGranted('delivery.ship') || $this->isGranted('quality.release')),
            $this->isGranted('quotations.view'),
            $this->isGranted('production.view') && $this->isGranted('production.design.review'),
            $this->isGranted('production.view') && $this->isGranted('production.execute'),
            $canManageProduction,
            $this->isGranted('production_calendar.manage'),
            $this->isGranted('delivery.confirm') || $this->isGranted('delivery.ship') || $this->isGranted('quality.release'),
        );

        $shortages = $this->isGranted('inventory.configure') ? $commitmentRepository->findShortages() : [];

        return $this->render('dashboard/index.html.twig', [
            'dashboard' => $dashboard,
            'lowStockAlerts' => $this->isGranted('inventory.configure')
                ? $variantRepository->findLowStockAlerts()
                : [],
            'materialShortageAlerts' => $shortages,
            'materialShortageDecisions' => $shortageDecisionRepository->findActiveForCommitments($shortages),
            'inventoryIndicators' => $this->isGranted('inventory.configure')
                ? $indicatorService->collect(includeDetails: false)
                : null,
        ]);
    }
}
