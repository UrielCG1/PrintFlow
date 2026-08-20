<?php

declare(strict_types=1);

namespace App\Controller\Admin\Catalog;

use App\Application\Catalog\CommercialCharacteristicData;
use App\Application\Catalog\CommercialCharacteristicManager;
use App\Application\Catalog\CommercialCharacteristicOptionData;
use App\Application\Catalog\CommercialCharacteristicTechnicalContract;
use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialCharacteristicOption;
use App\Entity\Users\User;
use App\Enum\Catalog\CommercialCharacteristicInputType;
use App\Enum\Catalog\CommercialItemType;
use App\Form\Admin\Catalog\CommercialCharacteristicOptionType;
use App\Form\Admin\Catalog\CommercialCharacteristicType;
use App\Repository\Catalog\CommercialCharacteristicOptionRepository;
use App\Repository\Catalog\CommercialCharacteristicRepository;
use App\Repository\Catalog\CommercialItemCharacteristicRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/catalogo/caracteristicas', name: 'admin_catalog_characteristics_')]
final class CommercialCharacteristicController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        CommercialCharacteristicRepository $repository,
        CommercialCharacteristicOptionRepository $optionRepository,
        CommercialItemCharacteristicRepository $configurationRepository,
        CommercialCharacteristicTechnicalContract $technicalContract,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.view');

        $status = $request->query->getString('status', 'active');
        if (!in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'active';
        }

        $type = strtolower($request->query->getString('type', 'all'));
        if (!in_array($type, ['all', 'select', 'decimal', 'text', 'boolean'], true)) {
            $type = 'all';
        }

        $inputType = match ($type) {
            'select' => CommercialCharacteristicInputType::SELECT,
            'decimal' => CommercialCharacteristicInputType::DECIMAL,
            'text' => CommercialCharacteristicInputType::TEXT,
            'boolean' => CommercialCharacteristicInputType::BOOLEAN,
            default => null,
        };

        $page = $repository->paginateForAdministration(
            search: $request->query->getString('q'),
            isActive: match ($status) {
                'active' => true,
                'inactive' => false,
                default => null,
            },
            inputType: $inputType,
            page: $request->query->getInt('page', 1),
        );

        $characteristicIds = array_values(array_filter(array_map(
            static fn (CommercialCharacteristic $characteristic): ?int => $characteristic->getId(),
            $page['items'],
        )));

        $technicalContracts = [];
        foreach ($page['items'] as $characteristic) {
            $contract = $technicalContract->forCharacteristic($characteristic);
            if ($contract !== null && $characteristic->getId() !== null) {
                $technicalContracts[$characteristic->getId()] = $contract;
            }
        }

        return $this->render('admin/catalog/characteristics/index.html.twig', [
            'page' => $page,
            'optionSummary' => $optionRepository->summarizeByCharacteristicIds($characteristicIds),
            'usageSummary' => $configurationRepository->summarizeUsageByCharacteristicIds($characteristicIds),
            'technicalContracts' => $technicalContracts,
            'search' => $request->query->getString('q'),
            'status' => $status,
            'type' => $type,
        ]);
    }

    #[Route('/ordenar', name: 'order', methods: ['GET'])]
    public function order(CommercialCharacteristicRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('catalog.characteristics.manage');

        return $this->render('admin/catalog/characteristics/order.html.twig', [
            'characteristics' => $repository->findActiveOrdered(),
        ]);
    }

    #[Route('/reordenar', name: 'reorder', methods: ['POST'])]
    public function reorder(Request $request, CommercialCharacteristicManager $manager): JsonResponse
    {
        $this->denyAccessUnlessGranted('catalog.characteristics.manage');

        $payload = $this->decodeReorderPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        if (!$this->isCsrfTokenValid('catalog_characteristic_reorder', (string) ($payload['_token'] ?? ''))) {
            return $this->json(['message' => 'La solicitud no es válida.'], Response::HTTP_FORBIDDEN);
        }

        $position = $this->normalizeReorderPosition($payload);
        if ($position === null) {
            return $this->json(['message' => 'La posición recibida no es válida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $manager->reorderActive(
                $position['movedId'],
                $position['beforeId'],
                $position['afterId'],
                $this->getActor(),
            );
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['message' => 'Orden actualizado correctamente.']);
    }

    #[Route('/nueva', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, CommercialCharacteristicManager $manager): Response
    {
        $this->denyAccessUnlessGranted('catalog.characteristics.manage');

        $data = new CommercialCharacteristicData();
        $form = $this->createForm(CommercialCharacteristicType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $characteristic = $manager->create($data, $this->getActor());
                $this->addFlash('success', 'Característica registrada correctamente. Completa su configuración.');

                return $this->redirectToRoute('admin_catalog_characteristics_configure', ['id' => $characteristic->getId()]);
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError('Ya existe otra característica con ese código o nombre.'));
            }
        }

        return $this->render('admin/catalog/characteristics/form.html.twig', [
            'form' => $form,
            'characteristic' => null,
            'technicalContract' => null,
            'definitionLocked' => false,
            'pageTitle' => 'Nueva característica',
        ]);
    }

    #[Route('/{id}/configurar', name: 'configure', methods: ['GET'])]
    public function configure(
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicOptionRepository $optionRepository,
        CommercialItemCharacteristicRepository $configurationRepository,
        CommercialCharacteristicTechnicalContract $technicalContract,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.view');

        $options = $characteristic->getInputType()->supportsOptions()
            ? $optionRepository->findForCharacteristic($characteristic)
            : [];
        $usage = $configurationRepository->findUsageForCharacteristic($characteristic);

        return $this->render('admin/catalog/characteristics/configure.html.twig', [
            'characteristic' => $characteristic,
            'technicalContract' => $technicalContract->forCharacteristic($characteristic),
            'optionCount' => count($options),
            'activeOptionCount' => count(array_filter($options, static fn (CommercialCharacteristicOption $option): bool => $option->isActive())),
            'usageCount' => count($usage),
            'activeUsageCount' => count(array_filter(
                $usage,
                static fn ($configuration): bool => $configuration->getCommercialItem()->isActive()
                    && $configuration->getCommercialItem()->getType() === CommercialItemType::PRODUCT,
            )),
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicManager $manager,
        CommercialCharacteristicTechnicalContract $technicalContract,
        CommercialItemCharacteristicRepository $configurationRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.characteristics.manage');

        $data = new CommercialCharacteristicData();
        $data->id = $characteristic->getId();
        $data->code = $characteristic->getCode();
        $data->name = $characteristic->getName();
        $data->inputType = $characteristic->getInputType();
        $data->unitLabel = $characteristic->getUnitLabel();
        $data->displayOrder = $characteristic->getDisplayOrder();
        $contract = $technicalContract->forCharacteristic($characteristic);

        $definitionLocked = $configurationRepository->hasConfigurationForCharacteristic($characteristic);
        $form = $this->createForm(CommercialCharacteristicType::class, $data, [
            'technical_contract' => $contract,
            'lock_definition' => $definitionLocked,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $manager->update($characteristic, $data, $this->getActor());
                $this->addFlash('success', 'Característica actualizada correctamente.');

                return $this->redirectToRoute('admin_catalog_characteristics_configure', ['id' => $characteristic->getId()]);
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError('Ya existe otra característica con ese código o nombre.'));
            }
        }

        return $this->render('admin/catalog/characteristics/form.html.twig', [
            'form' => $form,
            'characteristic' => $characteristic,
            'technicalContract' => $contract,
            'definitionLocked' => $definitionLocked,
            'pageTitle' => 'Editar característica',
        ]);
    }

    #[Route('/{id}/estado', name: 'status', methods: ['POST'])]
    public function status(
        Request $request,
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicManager $manager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.characteristics.manage');
        $this->assertCsrf('catalog_characteristic_status_'.$characteristic->getId(), $request);

        try {
            $manager->setActive($characteristic, !$characteristic->isActive(), $this->getActor());
            $this->addFlash('success', $characteristic->isActive()
                ? 'Característica reactivada correctamente.'
                : 'Característica desactivada correctamente.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        if ($request->request->getString('_return_to') === 'configure') {
            return $this->redirectToRoute('admin_catalog_characteristics_configure', ['id' => $characteristic->getId()]);
        }

        return $this->redirectToRoute('admin_catalog_characteristics_index', $request->query->all());
    }

    #[Route('/{id}/opciones', name: 'options', methods: ['GET'])]
    public function options(
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicOptionRepository $optionRepository,
        CommercialItemCharacteristicRepository $configurationRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.view');
        $this->assertSupportsOptions($characteristic);

        $options = $optionRepository->findForCharacteristic($characteristic);
        $optionIds = array_values(array_filter(array_map(
            static fn (CommercialCharacteristicOption $option): ?int => $option->getId(),
            $options,
        )));

        return $this->render('admin/catalog/characteristics/options.html.twig', [
            'characteristic' => $characteristic,
            'options' => $options,
            'usageSummary' => $configurationRepository->summarizeUsageByOptionIds($optionIds),
        ]);
    }

    #[Route('/{id}/opciones/ordenar', name: 'options_order', methods: ['GET'])]
    public function optionsOrder(
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicOptionRepository $optionRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.characteristics.manage');
        $this->assertSupportsOptions($characteristic);

        return $this->render('admin/catalog/characteristics/options_order.html.twig', [
            'characteristic' => $characteristic,
            'options' => $optionRepository->findActiveForCharacteristic($characteristic),
        ]);
    }

    #[Route('/{id}/opciones/reordenar', name: 'options_reorder', methods: ['POST'])]
    public function optionsReorder(
        Request $request,
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicManager $manager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('catalog.characteristics.manage');
        $this->assertSupportsOptions($characteristic);

        $payload = $this->decodeReorderPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        if (!$this->isCsrfTokenValid('catalog_characteristic_option_reorder_'.$characteristic->getId(), (string) ($payload['_token'] ?? ''))) {
            return $this->json(['message' => 'La solicitud no es válida.'], Response::HTTP_FORBIDDEN);
        }

        $position = $this->normalizeReorderPosition($payload);
        if ($position === null) {
            return $this->json(['message' => 'La posición recibida no es válida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $manager->reorderActiveOptions(
                $characteristic,
                $position['movedId'],
                $position['beforeId'],
                $position['afterId'],
                $this->getActor(),
            );
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['message' => 'Orden de opciones actualizado correctamente.']);
    }

    #[Route('/{id}/productos', name: 'products', methods: ['GET'])]
    public function products(
        CommercialCharacteristic $characteristic,
        CommercialItemCharacteristicRepository $configurationRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.view');

        return $this->render('admin/catalog/characteristics/products.html.twig', [
            'characteristic' => $characteristic,
            'configurations' => $configurationRepository->findUsageForCharacteristic($characteristic),
        ]);
    }

    #[Route('/{id}/opciones/nueva', name: 'option_new', methods: ['GET', 'POST'])]
    public function newOption(
        Request $request,
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicManager $manager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.characteristics.manage');
        $this->assertSupportsOptions($characteristic);

        $data = new CommercialCharacteristicOptionData();
        $data->characteristic = $characteristic;
        $form = $this->createForm(CommercialCharacteristicOptionType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $manager->createOption($characteristic, $data, $this->getActor());
                $this->addFlash('success', 'Opción registrada correctamente.');

                return $this->redirectToRoute('admin_catalog_characteristics_options', ['id' => $characteristic->getId()]);
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError('Ya existe otra opción con ese código o nombre dentro de la característica.'));
            }
        }

        return $this->render('admin/catalog/characteristics/option_form.html.twig', [
            'form' => $form,
            'characteristic' => $characteristic,
            'option' => null,
            'optionCodeLocked' => false,
            'pageTitle' => 'Nueva opción',
        ]);
    }

    #[Route('/{id}/opciones/{option}/editar', name: 'option_edit', methods: ['GET', 'POST'])]
    public function editOption(
        Request $request,
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicOption $option,
        CommercialCharacteristicManager $manager,
        CommercialItemCharacteristicRepository $configurationRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.characteristics.manage');
        $this->assertOptionBelongsToCharacteristic($option, $characteristic);

        $data = new CommercialCharacteristicOptionData();
        $data->id = $option->getId();
        $data->characteristic = $characteristic;
        $data->code = $option->getCode();
        $data->name = $option->getName();
        $data->displayOrder = $option->getDisplayOrder();
        $optionCodeLocked = $configurationRepository->hasProductForCharacteristicOption($option);
        $form = $this->createForm(CommercialCharacteristicOptionType::class, $data, [
            'lock_code' => $optionCodeLocked,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $manager->updateOption($option, $data, $this->getActor());
                $this->addFlash('success', 'Opción actualizada correctamente.');

                return $this->redirectToRoute('admin_catalog_characteristics_options', ['id' => $characteristic->getId()]);
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError('Ya existe otra opción con ese código o nombre dentro de la característica.'));
            }
        }

        return $this->render('admin/catalog/characteristics/option_form.html.twig', [
            'form' => $form,
            'characteristic' => $characteristic,
            'option' => $option,
            'optionCodeLocked' => $optionCodeLocked,
            'pageTitle' => 'Editar opción',
        ]);
    }

    #[Route('/{id}/opciones/{option}/estado', name: 'option_status', methods: ['POST'])]
    public function optionStatus(
        Request $request,
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicOption $option,
        CommercialCharacteristicManager $manager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.characteristics.manage');
        $this->assertOptionBelongsToCharacteristic($option, $characteristic);
        $this->assertCsrf('catalog_characteristic_option_status_'.$option->getId(), $request);

        try {
            $manager->setOptionActive($option, !$option->isActive(), $this->getActor());
            $this->addFlash('success', $option->isActive()
                ? 'Opción reactivada correctamente.'
                : 'Opción desactivada correctamente.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_catalog_characteristics_options', ['id' => $characteristic->getId()]);
    }

    private function assertSupportsOptions(CommercialCharacteristic $characteristic): void
    {
        if (!$characteristic->getInputType()->supportsOptions()) {
            throw $this->createNotFoundException('Esta característica no utiliza opciones catalogadas.');
        }
    }

    private function assertOptionBelongsToCharacteristic(
        CommercialCharacteristicOption $option,
        CommercialCharacteristic $characteristic,
    ): void {
        if ($option->getCharacteristic()->getId() !== $characteristic->getId()) {
            throw $this->createNotFoundException();
        }
    }

    private function assertCsrf(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }
    }

    /** @return array<string, mixed>|JsonResponse */
    private function decodeReorderPayload(Request $request): array|JsonResponse
    {
        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

            return $payload;
        } catch (\JsonException) {
            return $this->json(['message' => 'La solicitud no contiene datos válidos.'], Response::HTTP_BAD_REQUEST);
        }
    }

    /** @param array<string, mixed> $payload @return array{movedId: int, beforeId: ?int, afterId: ?int}|null */
    private function normalizeReorderPosition(array $payload): ?array
    {
        $movedId = filter_var($payload['movedId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $beforeId = ($payload['beforeId'] ?? null) === null
            ? null
            : filter_var($payload['beforeId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $afterId = ($payload['afterId'] ?? null) === null
            ? null
            : filter_var($payload['afterId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($movedId === false || $beforeId === false || $afterId === false) {
            return null;
        }

        return [
            'movedId' => (int) $movedId,
            'beforeId' => $beforeId === null ? null : (int) $beforeId,
            'afterId' => $afterId === null ? null : (int) $afterId,
        ];
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
