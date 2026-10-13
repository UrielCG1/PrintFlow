<?php

declare(strict_types=1);

namespace App\Controller\Admin\Inventory;

use App\Application\Inventory\InventoryPurchaseRequestCommand;
use App\Application\Inventory\InventoryPurchaseRequestDemandReview;
use App\Application\Inventory\InventoryPurchaseRequestDemandReviewService;
use App\Application\Inventory\InventoryPurchaseRequestLineInput;
use App\Application\Inventory\InventoryPurchaseRequestManager;
use App\Entity\Inventory\InventoryPurchaseRequest;
use App\Entity\Users\User;
use App\Repository\Materials\MaterialVariantRepository;
use App\Repository\Suppliers\SupplierMaterialVariantRepository;
use App\Repository\Inventory\InventoryPurchaseRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/inventario/compras', name: 'admin_inventory_purchase_requests_')]
final class InventoryPurchaseRequestController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        InventoryPurchaseRequestRepository $purchaseRequestRepository,
        InventoryPurchaseRequestDemandReviewService $demandReviewService,
    ): Response
    {
        $this->denyAccessUnlessGranted('inventory.configure');
        $requests = $purchaseRequestRepository->findOpen();
        $demandReviewCounts = [];
        foreach ($demandReviewService->reviewMany($requests) as $requestId => $reviews) {
            $demandReviewCounts[$requestId] = count(array_filter($reviews, static fn (InventoryPurchaseRequestDemandReview $review): bool => $review->requiresReview));
        }

        return $this->render('admin/inventory/purchase_requests/index.html.twig', [
            'requests' => $requests,
            'demandReviewCounts' => $demandReviewCounts,
        ]);
    }

    #[Route('/crear', name: 'create', methods: ['POST'])]
    public function create(
        Request $request,
        MaterialVariantRepository $variantRepository,
        SupplierMaterialVariantRepository $supplierMaterialVariantRepository,
        InventoryPurchaseRequestManager $purchaseRequestManager,
    ): Response {
        $this->denyAccessUnlessGranted('inventory.configure');
        if (!$this->isCsrfTokenValid('inventory_purchase_request_create', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        $variantId = $this->positiveId($request->request->getString('variant_id'));
        $variant = $variantId === null ? null : $variantRepository->find($variantId);
        $offerId = $this->positiveId($request->request->getString('supplier_offer_id'));
        $offer = $offerId === null ? null : $supplierMaterialVariantRepository->find($offerId);
        if ($variant === null) {
            $this->addFlash('error', 'El material seleccionado ya no existe.');

            return $this->redirectToRoute('admin_inventory_replenishment_index');
        }
        if ($offerId !== null && $offer === null) {
            $this->addFlash('error', 'La oferta de proveedor seleccionada ya no existe.');

            return $this->redirectToRoute('admin_inventory_replenishment_index');
        }

        try {
            $purchaseRequest = $purchaseRequestManager->create(new InventoryPurchaseRequestCommand(
                $request->request->getString('idempotency_key'),
                [new InventoryPurchaseRequestLineInput(
                    $variant,
                    $request->request->getString('quantity'),
                    $offer,
                )],
                $request->request->getString('notes'),
            ), $this->getActor());
            $this->addFlash('success', 'Solicitud '.$purchaseRequest->getRequestCode().' preparada. Pásala a seguimiento cuando la compra quede confirmada.');

            return $this->redirectToRoute('admin_inventory_purchase_requests_show', ['id' => $purchaseRequest->getId()]);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('admin_inventory_replenishment_index');
        }
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(
        InventoryPurchaseRequest $purchaseRequest,
        InventoryPurchaseRequestDemandReviewService $demandReviewService,
    ): Response
    {
        $this->denyAccessUnlessGranted('inventory.configure');
        $demandReviews = $demandReviewService->review($purchaseRequest);

        return $this->render('admin/inventory/purchase_requests/show.html.twig', [
            'purchaseRequest' => $purchaseRequest,
            'demandReviews' => $demandReviews,
            'demandReviewCount' => count(array_filter($demandReviews, static fn (InventoryPurchaseRequestDemandReview $review): bool => $review->requiresReview)),
        ]);
    }

    #[Route('/{id}/iniciar-seguimiento', name: 'begin_tracking', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function beginTracking(
        Request $request,
        InventoryPurchaseRequest $purchaseRequest,
        InventoryPurchaseRequestManager $purchaseRequestManager,
    ): Response {
        $this->denyAccessUnlessGranted('inventory.configure');
        if (!$this->isCsrfTokenValid('inventory_purchase_request_tracking_'.$purchaseRequest->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $purchaseRequestManager->beginTracking($purchaseRequest, $request->request->getString('tracking_reference'), $this->getActor());
            $this->addFlash('success', 'La compra quedó en seguimiento. Su saldo pendiente ya se descuenta de la recomendación de reabasto, pero no del inventario físico.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_inventory_purchase_requests_show', ['id' => $purchaseRequest->getId()]);
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
