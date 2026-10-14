<?php

declare(strict_types=1);

namespace App\Controller\Admin\Inventory;

use App\Application\Inventory\InventoryReceiptCommand;
use App\Application\Inventory\InventoryReceiptLineInput;
use App\Application\Inventory\InventoryReceiptManager;
use App\Entity\Inventory\InventoryReceipt;
use App\Entity\Inventory\InventoryPurchaseRequestLine;
use App\Entity\Inventory\InventoryStockUnit;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Users\User;
use App\Enum\Inventory\InventoryStockUnitStatus;
use App\Repository\Inventory\InventoryStockUnitRepository;
use App\Repository\Inventory\InventoryPurchaseRequestLineRepository;
use App\Repository\Materials\MaterialVariantRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Inventory\InventoryQrCodeGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/inventario/recepciones', name: 'admin_inventory_receipts_')]
final class InventoryReceiptController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, InventoryStockUnitRepository $stockUnitRepository): Response
    {
        $this->denyAccessUnlessGranted('inventory.view');

        $statusValue = $request->query->getString('status');
        $status = $statusValue === '' ? null : InventoryStockUnitStatus::tryFrom($statusValue);

        return $this->render('admin/inventory/receipts/index.html.twig', [
            'page' => $stockUnitRepository->paginateForInventory(
                $request->query->getString('q'),
                $status,
                $request->query->getInt('page', 1),
            ),
            'search' => $request->query->getString('q'),
            'status' => $status?->value ?? '',
            'statuses' => InventoryStockUnitStatus::cases(),
        ]);
    }

    #[Route('/nueva', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        MaterialVariantRepository $variantRepository,
        InventoryPurchaseRequestLineRepository $purchaseRequestLineRepository,
        InventoryReceiptManager $receiptManager,
    ): Response {
        $this->denyAccessUnlessGranted('inventory.receive');

        $linkedPurchaseRequestLine = null;
        $purchaseRequestLineId = $this->positiveId($request->query->getString('purchase_request_line'));
        if ($purchaseRequestLineId !== null) {
            $linkedPurchaseRequestLine = $purchaseRequestLineRepository->find($purchaseRequestLineId);
            if (!$linkedPurchaseRequestLine instanceof InventoryPurchaseRequestLine) {
                $this->addFlash('error', 'La partida de solicitud de compra indicada no existe.');
            }
        }

        $formData = [
            'idempotency_key' => bin2hex(random_bytes(16)),
            'variant_id' => $linkedPurchaseRequestLine?->getVariant()->getId() ?? '',
            'quantity' => '',
            'storage_location' => '',
            'physical_description' => '',
            'unit_cost_mxn' => '',
            'reference' => $linkedPurchaseRequestLine?->getPurchaseRequest()->getRequestCode() ?? '',
            'notes' => '',
            'is_provisional' => false,
            'purchase_request_line_id' => $linkedPurchaseRequestLine?->getId() ?? '',
        ];

        if ($request->isMethod('POST')) {
            $formData = [
                'idempotency_key' => $request->request->getString('idempotency_key'),
                'variant_id' => $request->request->getString('variant_id'),
                'quantity' => $request->request->getString('quantity'),
                'storage_location' => $request->request->getString('storage_location'),
                'physical_description' => $request->request->getString('physical_description'),
                'unit_cost_mxn' => $request->request->getString('unit_cost_mxn'),
                'reference' => $request->request->getString('reference'),
                'notes' => $request->request->getString('notes'),
                'is_provisional' => $request->request->getBoolean('is_provisional'),
                'purchase_request_line_id' => $request->request->getString('purchase_request_line_id'),
            ];

            if (!$this->isCsrfTokenValid('inventory_receipt_new', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('La solicitud no es válida.');
            }

            $variantId = $this->positiveId($formData['variant_id']);
            $variant = $variantId === null ? null : $variantRepository->find($variantId);
            $purchaseRequestLineId = $this->positiveId($formData['purchase_request_line_id']);
            $linkedPurchaseRequestLine = $purchaseRequestLineId === null ? null : $purchaseRequestLineRepository->find($purchaseRequestLineId);
            if ($purchaseRequestLineId !== null && !$linkedPurchaseRequestLine instanceof InventoryPurchaseRequestLine) {
                $this->addFlash('error', 'La partida de solicitud de compra indicada no existe.');
            } elseif ($variant === null) {
                $this->addFlash('error', 'Selecciona una presentación de material válida.');
            } else {
                try {
                    $physicalDescription = trim($formData['physical_description']);
                    $receipt = $receiptManager->receive(
                        new InventoryReceiptCommand(
                            idempotencyKey: $formData['idempotency_key'],
                            lines: [new InventoryReceiptLineInput(
                                variant: $variant,
                                quantity: $formData['quantity'],
                                storageLocation: $formData['storage_location'],
                                physicalAttributes: $physicalDescription === '' ? [] : ['description' => $physicalDescription],
                                unitCostMxn: $formData['unit_cost_mxn'],
                                purchaseRequestLine: $linkedPurchaseRequestLine,
                            )],
                            reference: $formData['reference'],
                            notes: $formData['notes'],
                            isProvisional: $formData['is_provisional'],
                        ),
                        $this->getActor(),
                    );

                    $this->addFlash('success', sprintf('Recepción %s registrada. Imprime la etiqueta antes de llevar la unidad a bodega.', $receipt->getReceiptCode()));

                    return $this->redirectToRoute('admin_inventory_receipts_label', ['id' => $receipt->getId()]);
                } catch (\DomainException|\InvalidArgumentException $exception) {
                    $this->addFlash('error', $exception->getMessage());
                }
            }
        }

        return $this->render('admin/inventory/receipts/new.html.twig', [
            'formData' => $formData,
            'variants' => $variantRepository->findActiveConsumable(),
            'purchaseRequestLine' => $linkedPurchaseRequestLine,
        ]);
    }

    #[Route('/conteo-inicial', name: 'initial_count', methods: ['GET', 'POST'])]
    public function initialCount(
        Request $request,
        MaterialVariantRepository $variantRepository,
        InventoryReceiptManager $receiptManager,
    ): Response {
        $this->denyAccessUnlessGranted('inventory.adjust_stock');

        $formData = [
            'idempotency_key' => bin2hex(random_bytes(16)),
            'variant_id' => '',
            'quantity' => '',
            'storage_location' => '',
            'physical_description' => '',
            'reference' => 'CONTEO-INICIAL',
            'notes' => '',
        ];

        if ($request->isMethod('POST')) {
            $formData = [
                'idempotency_key' => $request->request->getString('idempotency_key'),
                'variant_id' => $request->request->getString('variant_id'),
                'quantity' => $request->request->getString('quantity'),
                'storage_location' => $request->request->getString('storage_location'),
                'physical_description' => $request->request->getString('physical_description'),
                'reference' => $request->request->getString('reference'),
                'notes' => $request->request->getString('notes'),
            ];
            if (!$this->isCsrfTokenValid('inventory_initial_count', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('La solicitud no es válida.');
            }

            $variantId = $this->positiveId($formData['variant_id']);
            $variant = $variantId === null ? null : $variantRepository->find($variantId);
            if ($variant === null) {
                $this->addFlash('error', 'Selecciona una presentación de material válida.');
            } else {
                try {
                    $physicalDescription = trim($formData['physical_description']);
                    $receipt = $receiptManager->recordInitialCount(new InventoryReceiptCommand(
                        idempotencyKey: $formData['idempotency_key'],
                        lines: [new InventoryReceiptLineInput(
                            variant: $variant,
                            quantity: $formData['quantity'],
                            storageLocation: $formData['storage_location'],
                            physicalAttributes: $physicalDescription === '' ? [] : ['description' => $physicalDescription],
                        )],
                        reference: $formData['reference'],
                        notes: $formData['notes'],
                    ), $this->getActor());

                    $this->addFlash('success', sprintf('Existencia previa %s registrada. Imprime la etiqueta antes de almacenar la unidad.', $receipt->getReceiptCode()));

                    return $this->redirectToRoute('admin_inventory_receipts_label', ['id' => $receipt->getId()]);
                } catch (\DomainException|\InvalidArgumentException $exception) {
                    $this->addFlash('error', $exception->getMessage());
                }
            }
        }

        return $this->render('admin/inventory/receipts/initial_count.html.twig', [
            'formData' => $formData,
            'variants' => $variantRepository->findActiveConsumable(),
        ]);
    }

    #[Route('/minimos', name: 'minimums', methods: ['GET'])]
    public function minimums(MaterialVariantRepository $variantRepository): Response
    {
        $this->denyAccessUnlessGranted('inventory.configure');

        return $this->render('admin/inventory/receipts/minimums.html.twig', [
            'variants' => $variantRepository->findActiveStockItems(),
        ]);
    }

    #[Route('/minimos/{id}', name: 'minimums_update', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function updateMinimums(
        Request $request,
        MaterialVariant $variant,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): Response {
        $this->denyAccessUnlessGranted('inventory.configure');
        if (!$this->isCsrfTokenValid('inventory_thresholds_'.$variant->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $previous = [
                'minimum_stock' => $variant->getMinimumStock(),
                'reorder_point' => $variant->getReorderPoint(),
                'reorder_quantity' => $variant->getReorderQuantity(),
            ];
            $variant->setInventoryThresholds(
                $request->request->getString('minimum_stock'),
                $request->request->getString('reorder_point'),
                $request->request->getString('reorder_quantity'),
            );
            $auditLogger->record($this->getActor(), 'inventory.thresholds.updated', 'material_variant', $variant->getId(), $previous, [
                'minimum_stock' => $variant->getMinimumStock(),
                'reorder_point' => $variant->getReorderPoint(),
                'reorder_quantity' => $variant->getReorderQuantity(),
            ]);
            $entityManager->flush();
            $this->addFlash('success', 'Mínimos y reabasto actualizados para '.$variant->getCode().'.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_inventory_receipts_minimums');
    }

    #[Route('/{id}/etiqueta', name: 'label', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function label(InventoryReceipt $receipt, InventoryQrCodeGenerator $qrCodeGenerator): Response
    {
        $this->denyAccessUnlessGranted('inventory.labels.print');

        try {
            $labels = [];
            foreach ($receipt->getLines() as $line) {
                $labels[] = [
                    'line' => $line,
                    'qrSvg' => $qrCodeGenerator->generateSvg($line->getStockUnit()),
                ];
            }

            return $this->render('admin/inventory/receipts/label.html.twig', [
                'receipt' => $receipt,
                'labels' => $labels,
            ], new Response('', Response::HTTP_OK, [
                'Cache-Control' => 'private, no-store',
            ]));
        } catch (\RuntimeException $exception) {
            return $this->render('admin/inventory/receipts/label_pending.html.twig', [
                'receipt' => $receipt,
            ], new Response('', Response::HTTP_SERVICE_UNAVAILABLE, [
                'Cache-Control' => 'private, no-store',
            ]));
        }
    }

    #[Route('/unidades/{id}/etiqueta', name: 'stock_unit_label', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function stockUnitLabel(InventoryStockUnit $stockUnit, InventoryQrCodeGenerator $qrCodeGenerator): Response
    {
        $this->denyAccessUnlessGranted('inventory.labels.print');

        try {
            return $this->render('admin/inventory/receipts/stock_unit_label.html.twig', [
                'stockUnit' => $stockUnit,
                'qrSvg' => $qrCodeGenerator->generateSvg($stockUnit),
            ], new Response('', Response::HTTP_OK, [
                'Cache-Control' => 'private, no-store',
            ]));
        } catch (\RuntimeException $exception) {
            return $this->render('admin/inventory/receipts/stock_unit_label_pending.html.twig', [
                'stockUnit' => $stockUnit,
            ], new Response('', Response::HTTP_SERVICE_UNAVAILABLE, [
                'Cache-Control' => 'private, no-store',
            ]));
        }
    }

    private function getActor(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function positiveId(string $value): ?int
    {
        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}
