<?php

namespace App\Controller\Admin\Catalog;

use App\Application\Catalog\CommercialItemData;
use App\Application\Catalog\CommercialItemManager;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Users\User;
use App\Enum\Catalog\CommercialItemType;
use App\Form\Admin\Catalog\CommercialItemType as CommercialItemFormType;
use App\Repository\Catalog\CommercialCategoryRepository;
use App\Repository\Catalog\CommercialItemCharacteristicRepository;
use App\Repository\Catalog\CommercialItemRepository;
use App\Repository\Catalog\ItemPriceRuleRepository;
use App\Repository\Catalog\MeasurementUnitRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/catalogo/conceptos', name: 'admin_catalog_items_')]
final class CommercialItemController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        CommercialItemRepository $commercialItemRepository,
        CommercialCategoryRepository $commercialCategoryRepository,
        MeasurementUnitRepository $measurementUnitRepository,
        ItemPriceRuleRepository $itemPriceRuleRepository,
        CommercialItemCharacteristicRepository $itemCharacteristicRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.view');

        $status = $request->query->getString('status', 'active');
        if (!in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'active';
        }

        $type = $request->query->getString('type', 'all');
        if (!in_array($type, ['all', 'product', 'service'], true)) {
            $type = 'all';
        }

        $isActive = match ($status) {
            'active' => true,
            'inactive' => false,
            default => null,
        };

        $typeFilter = match ($type) {
            'product' => CommercialItemType::PRODUCT,
            'service' => CommercialItemType::SERVICE,
            default => null,
        };

        $categoryId = max(0, $request->query->getInt('category'));
        $measurementUnitId = max(0, $request->query->getInt('unit'));

        $page = $commercialItemRepository->paginateForAdministration(
            search: $request->query->getString('q'),
            isActive: $isActive,
            type: $typeFilter,
            categoryId: $categoryId > 0 ? $categoryId : null,
            measurementUnitId: $measurementUnitId > 0 ? $measurementUnitId : null,
            page: $request->query->getInt('page', 1),
        );

        $itemIds = array_values(array_filter(array_map(
            static fn (CommercialItem $item): ?int => $item->getId(),
            $page['items'],
        )));

        return $this->render('admin/catalog/items/index.html.twig', [
            'page' => $page,
            'priceRuleSummary' => $itemPriceRuleRepository->summarizeQuantityTiersForItemIds($itemIds),
            'characteristicCounts' => $itemCharacteristicRepository->countByItemIds($itemIds),
            'categories' => $commercialCategoryRepository->findBy([], [
                'isActive' => 'DESC',
                'displayOrder' => 'ASC',
                'name' => 'ASC',
            ]),
            'measurementUnits' => $measurementUnitRepository->findBy([], [
                'isActive' => 'DESC',
                'displayOrder' => 'ASC',
                'name' => 'ASC',
            ]),
            'search' => $request->query->getString('q'),
            'status' => $status,
            'type' => $type,
            'categoryId' => $categoryId,
            'measurementUnitId' => $measurementUnitId,
        ]);
    }

    #[Route('/nuevo', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        CommercialItemManager $commercialItemManager,
        CommercialCategoryRepository $commercialCategoryRepository,
        MeasurementUnitRepository $measurementUnitRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.items.create');

        $data = new CommercialItemData();

        $form = $this->createForm(CommercialItemFormType::class, $data, [
            'categories' => $commercialCategoryRepository->findAvailableForItemForm(),
            'measurement_units' => $measurementUnitRepository->findAvailableForItemForm(),
            'can_edit_price' => true,
            'show_base_price' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $item = $commercialItemManager->create($data, $this->getActor());

            $this->addFlash('success', 'Producto o servicio registrado correctamente. Completa su configuración comercial.');

            return $this->redirectToRoute('admin_catalog_items_configure', [
                'id' => $item->getId(),
            ]);
        }

        return $this->render('admin/catalog/items/form.html.twig', [
            'form' => $form,
            'item' => null,
            'pageTitle' => 'Nuevo producto o servicio',
            'isCreate' => true,
        ]);
    }

    #[Route('/{id}/configurar', name: 'configure', methods: ['GET'])]
    public function configure(
        CommercialItem $item,
        ItemPriceRuleRepository $itemPriceRuleRepository,
        CommercialItemCharacteristicRepository $itemCharacteristicRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.view');

        $rules = $itemPriceRuleRepository->findQuantityTiersForItem($item);
        $activeRuleCount = count(array_filter(
            $rules,
            static fn ($rule): bool => $rule->isActive(),
        ));

        $configurations = $item->getType() === CommercialItemType::PRODUCT
            ? $itemCharacteristicRepository->findForItem($item)
            : [];

        return $this->render('admin/catalog/items/configure.html.twig', [
            'item' => $item,
            'priceRuleCount' => count($rules),
            'activePriceRuleCount' => $activeRuleCount,
            'characteristicCount' => count($configurations),
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        CommercialItem $item,
        CommercialItemManager $commercialItemManager,
        CommercialCategoryRepository $commercialCategoryRepository,
        MeasurementUnitRepository $measurementUnitRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.items.update');

        $data = new CommercialItemData();
        $data->id = $item->getId();
        $data->code = $item->getCode();
        $data->type = $item->getType();
        $data->quotationSpecificationProfile = $item->getQuotationSpecificationProfile();
        $data->name = $item->getName();
        $data->description = $item->getDescription();
        $data->category = $item->getCategory();
        $data->measurementUnit = $item->getMeasurementUnit();
        $data->basePrice = $item->getBasePrice();

        $form = $this->createForm(CommercialItemFormType::class, $data, [
            'categories' => $commercialCategoryRepository->findAvailableForItemForm($item->getCategory()),
            'measurement_units' => $measurementUnitRepository->findAvailableForItemForm($item->getMeasurementUnit()),
            'can_edit_price' => false,
            'show_base_price' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $commercialItemManager->update(
                    $item,
                    $data,
                    false,
                    $this->getActor(),
                );

                $this->addFlash('success', 'Información general actualizada correctamente.');

                return $this->redirectToRoute('admin_catalog_items_configure', [
                    'id' => $item->getId(),
                ]);
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('admin/catalog/items/form.html.twig', [
            'form' => $form,
            'item' => $item,
            'pageTitle' => 'Editar información general',
            'isCreate' => false,
        ]);
    }

    #[Route('/{id}/estado', name: 'status', methods: ['POST'])]
    public function status(
        Request $request,
        CommercialItem $item,
        CommercialItemManager $commercialItemManager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.items.toggle_status');

        if (!$this->isCsrfTokenValid(
            'catalog_item_status_'.$item->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $commercialItemManager->setActive(
                $item,
                !$item->isActive(),
                $this->getActor(),
            );

            $this->addFlash(
                'success',
                $item->isActive()
                    ? 'Producto o servicio reactivado correctamente.'
                    : 'Producto o servicio desactivado correctamente.',
            );
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        $returnTo = $request->request->getString('_return_to');
        if ($returnTo === 'configure') {
            return $this->redirectToRoute('admin_catalog_items_configure', [
                'id' => $item->getId(),
            ]);
        }

        return $this->redirectToRoute(
            'admin_catalog_items_index',
            $request->query->all(),
        );
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
