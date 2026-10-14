<?php

declare(strict_types=1);

namespace App\Controller\Admin\Inventory;

use App\Application\Inventory\InventoryStockUnitOperationCommand;
use App\Application\Inventory\InventoryStockUnitOperationManager;
use App\Entity\Users\User;
use App\Enum\Inventory\InventoryStockUnitOperationReason;
use App\Enum\Inventory\InventoryStockUnitOperationType;
use App\Repository\Inventory\InventoryStockUnitOperationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/inventario/operaciones-bodega', name: 'admin_inventory_stock_unit_operations_')]
final class InventoryStockUnitOperationController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(InventoryStockUnitOperationRepository $operationRepository): Response
    {
        $this->denyAccessUnlessGranted('inventory.view');

        return $this->render('admin/inventory/stock_unit_operations/index.html.twig', [
            'canManage' => $this->isGranted('inventory.adjust_stock'),
            'operationTypes' => InventoryStockUnitOperationType::cases(),
            'reasons' => InventoryStockUnitOperationReason::cases(),
            'recentOperations' => $operationRepository->findRecent(),
        ]);
    }

    #[Route('/registrar', name: 'create', methods: ['POST'])]
    public function create(Request $request, InventoryStockUnitOperationManager $operationManager): Response
    {
        $this->denyAccessUnlessGranted('inventory.adjust_stock');
        if (!$this->isCsrfTokenValid('inventory_stock_unit_operation', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $type = InventoryStockUnitOperationType::tryFrom($request->request->getString('operation_type'));
            $reason = InventoryStockUnitOperationReason::tryFrom($request->request->getString('reason'));
            if ($type === null || $reason === null) {
                throw new \InvalidArgumentException('Selecciona una operación y un motivo válidos.');
            }

            $operation = $operationManager->perform(new InventoryStockUnitOperationCommand(
                $request->request->getString('qr_payload'),
                $type,
                $reason,
                $request->request->getString('note'),
                $request->request->getString('reference'),
            ), $this->getActor());

            $this->addFlash('success', match ($operation->getType()) {
                InventoryStockUnitOperationType::BLOCK => sprintf('Unidad %s bloqueada. Ya no se considera disponible para nuevas órdenes.', $operation->getStockUnit()->getUnitCode()),
                InventoryStockUnitOperationType::RELEASE => sprintf('Unidad %s liberada y disponible nuevamente.', $operation->getStockUnit()->getUnitCode()),
                InventoryStockUnitOperationType::DISPOSE => sprintf('Unidad %s retirada del inventario con movimiento trazable.', $operation->getStockUnit()->getUnitCode()),
            });
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_inventory_stock_unit_operations_index');
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
