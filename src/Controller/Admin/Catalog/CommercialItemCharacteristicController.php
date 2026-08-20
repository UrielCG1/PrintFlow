<?php

declare(strict_types=1);

namespace App\Controller\Admin\Catalog;

use App\Application\Catalog\CommercialItemCharacteristicData;
use App\Application\Catalog\CommercialItemCharacteristicManager;
use App\Application\Catalog\CommercialItemCharacteristicSelectionData;
use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\CommercialItemCharacteristic;
use App\Entity\Users\User;
use App\Enum\Catalog\CommercialItemType;
use App\Form\Admin\Catalog\CommercialItemCharacteristicSelectionType;
use App\Form\Admin\Catalog\CommercialItemCharacteristicType;
use App\Repository\Catalog\CommercialCharacteristicOptionRepository;
use App\Repository\Catalog\CommercialCharacteristicRepository;
use App\Repository\Catalog\CommercialItemCharacteristicRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/catalogo/conceptos/{item}/caracteristicas', name: 'admin_catalog_item_characteristics_')]
final class CommercialItemCharacteristicController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        CommercialItem $item,
        CommercialItemCharacteristicRepository $configurationRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.items.configure_characteristics');
        $this->assertConfigurableProduct($item);

        return $this->render('admin/catalog/item_characteristics/index.html.twig', [
            'item' => $item,
            'configurations' => $configurationRepository->findForItem($item),
        ]);
    }

    #[Route('/ordenar', name: 'order', methods: ['GET'])]
    public function order(
        CommercialItem $item,
        CommercialItemCharacteristicRepository $configurationRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.items.configure_characteristics');
        $this->assertConfigurableProduct($item);

        return $this->render('admin/catalog/item_characteristics/order.html.twig', [
            'item' => $item,
            'configurations' => $configurationRepository->findForItem($item),
        ]);
    }

    #[Route('/reordenar', name: 'reorder', methods: ['POST'])]
    public function reorder(
        Request $request,
        CommercialItem $item,
        CommercialItemCharacteristicManager $manager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('catalog.items.configure_characteristics');
        $this->assertConfigurableProduct($item);

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['message' => 'La solicitud no contiene datos válidos.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid('catalog_item_characteristic_reorder_'.$item->getId(), (string) ($payload['_token'] ?? ''))) {
            return $this->json(['message' => 'La solicitud no es válida.'], Response::HTTP_FORBIDDEN);
        }

        $movedId = filter_var($payload['movedId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $beforeId = ($payload['beforeId'] ?? null) === null
            ? null
            : filter_var($payload['beforeId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $afterId = ($payload['afterId'] ?? null) === null
            ? null
            : filter_var($payload['afterId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($movedId === false || $beforeId === false || $afterId === false) {
            return $this->json(['message' => 'La posición recibida no es válida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $manager->reorderForItem(
                $item,
                (int) $movedId,
                $beforeId === null ? null : (int) $beforeId,
                $afterId === null ? null : (int) $afterId,
                $this->getActor(),
            );
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['message' => 'Orden de captura actualizado correctamente.']);
    }

    #[Route('/nueva/seleccionar', name: 'select', methods: ['GET', 'POST'])]
    public function select(
        Request $request,
        CommercialItem $item,
        CommercialCharacteristicRepository $characteristicRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.items.configure_characteristics');
        $this->assertConfigurableProduct($item);

        $data = new CommercialItemCharacteristicSelectionData();
        $form = $this->createForm(CommercialItemCharacteristicSelectionType::class, $data, [
            'characteristics' => $characteristicRepository->findActiveNotConfiguredForItem($item),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $data->characteristic !== null) {
            return $this->redirectToRoute('admin_catalog_item_characteristics_new', [
                'item' => $item->getId(),
                'characteristic' => $data->characteristic->getId(),
            ]);
        }

        return $this->render('admin/catalog/item_characteristics/select.html.twig', [
            'form' => $form,
            'item' => $item,
        ]);
    }

    #[Route('/nueva/{characteristic}', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        CommercialItem $item,
        CommercialCharacteristic $characteristic,
        CommercialItemCharacteristicManager $manager,
        CommercialItemCharacteristicRepository $configurationRepository,
        CommercialCharacteristicOptionRepository $optionRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.items.configure_characteristics');
        $this->assertConfigurableProduct($item);

        if (!$characteristic->isActive()) {
            throw $this->createNotFoundException('La característica ya no está activa.');
        }

        if ($configurationRepository->findOneForItemAndCharacteristic($item, $characteristic) !== null) {
            $this->addFlash('warning', 'Esta característica ya está configurada para el Producto.');

            return $this->redirectToRoute('admin_catalog_item_characteristics_index', ['item' => $item->getId()]);
        }

        $data = new CommercialItemCharacteristicData();
        $form = $this->createConfigurationForm($data, $characteristic, $optionRepository);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $manager->create($item, $characteristic, $data, $this->getActor());
                $this->addFlash('success', 'Característica configurada para el Producto.');

                return $this->redirectToRoute('admin_catalog_item_characteristics_index', ['item' => $item->getId()]);
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('admin/catalog/item_characteristics/form.html.twig', [
            'form' => $form,
            'item' => $item,
            'characteristic' => $characteristic,
            'configuration' => null,
            'pageTitle' => 'Configurar característica',
        ]);
    }

    #[Route('/{configuration}/editar', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        CommercialItem $item,
        CommercialItemCharacteristic $configuration,
        CommercialItemCharacteristicManager $manager,
        CommercialCharacteristicOptionRepository $optionRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.items.configure_characteristics');
        $this->assertConfigurableProduct($item);
        $this->assertBelongsToItem($configuration, $item);

        $data = new CommercialItemCharacteristicData();
        $data->isRequired = $configuration->isRequired();
        $data->displayOrder = $configuration->getDisplayOrder();
        $selectedOptionIds = [];
        foreach ($configuration->getAllowedOptions() as $allowedOption) {
            $option = $allowedOption->getCharacteristicOption();
            $data->allowedOptions[] = $option;
            if ($option->getId() !== null) {
                $selectedOptionIds[] = $option->getId();
            }
        }

        $form = $this->createConfigurationForm(
            $data,
            $configuration->getCharacteristic(),
            $optionRepository,
            $selectedOptionIds,
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $manager->update($configuration, $data, $this->getActor());
                $this->addFlash('success', 'Configuración actualizada correctamente.');

                return $this->redirectToRoute('admin_catalog_item_characteristics_index', ['item' => $item->getId()]);
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('admin/catalog/item_characteristics/form.html.twig', [
            'form' => $form,
            'item' => $item,
            'characteristic' => $configuration->getCharacteristic(),
            'configuration' => $configuration,
            'pageTitle' => 'Editar configuración',
        ]);
    }

    #[Route('/{configuration}/eliminar', name: 'delete', methods: ['POST'])]
    public function delete(
        Request $request,
        CommercialItem $item,
        CommercialItemCharacteristic $configuration,
        CommercialItemCharacteristicManager $manager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.items.configure_characteristics');
        $this->assertConfigurableProduct($item);
        $this->assertBelongsToItem($configuration, $item);

        if (!$this->isCsrfTokenValid(
            'catalog_item_characteristic_delete_'.$configuration->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        $manager->remove($configuration, $this->getActor());
        $this->addFlash('success', 'Característica retirada del Producto.');

        return $this->redirectToRoute('admin_catalog_item_characteristics_index', ['item' => $item->getId()]);
    }

    private function createConfigurationForm(
        CommercialItemCharacteristicData $data,
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicOptionRepository $optionRepository,
        array $selectedOptionIds = [],
    ): \Symfony\Component\Form\FormInterface {
        return $this->createForm(CommercialItemCharacteristicType::class, $data, [
            'characteristic' => $characteristic,
            'available_options' => $optionRepository->findAvailableForConfiguration($characteristic, $selectedOptionIds),
        ]);
    }

    private function assertConfigurableProduct(CommercialItem $item): void
    {
        if ($item->getType() !== CommercialItemType::PRODUCT) {
            throw $this->createNotFoundException('Las características solo se configuran en Productos.');
        }
    }

    private function assertBelongsToItem(CommercialItemCharacteristic $configuration, CommercialItem $item): void
    {
        if ($configuration->getCommercialItem()->getId() !== $item->getId()) {
            throw $this->createNotFoundException();
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
}
