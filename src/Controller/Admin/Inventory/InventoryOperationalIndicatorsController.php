<?php

declare(strict_types=1);

namespace App\Controller\Admin\Inventory;

use App\Application\Inventory\InventoryOperationalIndicatorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/inventario/indicadores', name: 'admin_inventory_indicators_')]
final class InventoryOperationalIndicatorsController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(InventoryOperationalIndicatorService $indicatorService): Response
    {
        $this->denyAccessUnlessGranted('inventory.configure');

        return $this->render('admin/inventory/indicators/index.html.twig', [
            'indicators' => $indicatorService->collect(),
        ]);
    }
}
