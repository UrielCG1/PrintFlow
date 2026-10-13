<?php

namespace App\Controller;

use App\Application\Inventory\InventoryOperationalIndicatorService;
use App\Repository\Materials\MaterialVariantRepository;
use App\Repository\Inventory\InventoryCommitmentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(
        MaterialVariantRepository $variantRepository,
        InventoryCommitmentRepository $commitmentRepository,
        InventoryOperationalIndicatorService $indicatorService,
    ): Response
    {
        $this->denyAccessUnlessGranted('dashboard.view');

        return $this->render('dashboard/index.html.twig', [
            'lowStockAlerts' => $this->isGranted('inventory.configure')
                ? $variantRepository->findLowStockAlerts()
                : [],
            'materialShortageAlerts' => $this->isGranted('inventory.configure')
                ? $commitmentRepository->findShortages()
                : [],
            'inventoryIndicators' => $this->isGranted('inventory.configure')
                ? $indicatorService->collect(includeDetails: false)
                : null,
        ]);
    }
}
