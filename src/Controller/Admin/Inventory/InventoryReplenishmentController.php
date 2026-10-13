<?php

declare(strict_types=1);

namespace App\Controller\Admin\Inventory;

use App\Application\Inventory\InventoryReplenishmentPlanner;
use App\Application\Inventory\InventoryReplenishmentSuggestion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/inventario/reabasto', name: 'admin_inventory_replenishment_')]
final class InventoryReplenishmentController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(InventoryReplenishmentPlanner $planner): Response
    {
        $this->denyAccessUnlessGranted('inventory.configure');

        return $this->render('admin/inventory/replenishment/index.html.twig', [
            'suggestions' => $planner->plan(),
        ]);
    }

    #[Route('/exportar.csv', name: 'export', methods: ['GET'])]
    public function export(InventoryReplenishmentPlanner $planner): Response
    {
        $this->denyAccessUnlessGranted('inventory.configure');

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('No fue posible preparar la exportación de reposición.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [
            'Código de material',
            'Material',
            'Unidad de inventario',
            'Disponible',
            'Faltante de órdenes',
            'Brecha de mínimo',
            'Cantidad sugerida',
            'Órdenes con faltante',
            'Órdenes en espera de compra',
            'Proveedor propuesto',
            'SKU de proveedor',
            'Unidad de compra',
        ]);
        foreach ($planner->plan() as $suggestion) {
            fputcsv($stream, $this->exportRow($suggestion));
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        if ($contents === false) {
            throw new \RuntimeException('No fue posible leer la exportación de reposición.');
        }

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="solicitud-reabasto-'.(new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City')))->format('Y-m-d').'.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return list<string|int> */
    private function exportRow(InventoryReplenishmentSuggestion $suggestion): array
    {
        $offer = $suggestion->supplierOffer;

        return [
            $suggestion->variant->getCode(),
            $suggestion->variant->getMaterial()->getName(),
            $suggestion->variant->getInventoryUnit()->getSymbol(),
            $suggestion->calculation->availableQuantity,
            $suggestion->calculation->orderShortageQuantity,
            $suggestion->calculation->minimumGapQuantity,
            $suggestion->calculation->suggestedQuantity,
            $suggestion->shortageOrderCount,
            $suggestion->waitingPurchaseOrderCount,
            $offer?->getSupplier()->getBusinessName() ?? '',
            $offer?->getSupplierSku() ?? '',
            $offer?->getPurchaseUnit()->getSymbol() ?? '',
        ];
    }
}
