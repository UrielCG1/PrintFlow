<?php

namespace App\Controller;

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
    ): Response
    {
        $this->denyAccessUnlessGranted('dashboard.view');

        $shortages = $this->isGranted('inventory.configure') ? $commitmentRepository->findShortages() : [];

        return $this->render('dashboard/index.html.twig', [
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
