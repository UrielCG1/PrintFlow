<?php

namespace App\Controller\Admin\Catalog;

use App\Application\Catalog\MeasurementUnitData;
use App\Application\Catalog\MeasurementUnitManager;
use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Users\User;
use App\Enum\Catalog\MeasurementDimensionType;
use App\Form\Admin\Catalog\MeasurementUnitType;
use App\Repository\Catalog\CommercialItemRepository;
use App\Repository\Catalog\MeasurementUnitRepository;
use App\Repository\Materials\MaterialRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/catalogo/unidades', name: 'admin_catalog_units_')]
final class MeasurementUnitController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        MeasurementUnitRepository $measurementUnitRepository,
        CommercialItemRepository $commercialItemRepository,
        MaterialRepository $materialRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.view');

        $status = $request->query->getString('status', 'active');
        if (!in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'active';
        }

        $isActive = match ($status) {
            'active' => true,
            'inactive' => false,
            default => null,
        };

        $dimensionValue = strtoupper(trim($request->query->getString('dimension')));
        $dimension = $dimensionValue !== '' && $dimensionValue !== 'ALL'
            ? MeasurementDimensionType::tryFrom($dimensionValue)
            : null;

        $page = $measurementUnitRepository->paginateForAdministration(
            search: $request->query->getString('q'),
            isActive: $isActive,
            dimension: $dimension,
            page: $request->query->getInt('page', 1),
            perPage: 100,
        );

        $unitIds = array_values(array_filter(array_map(
            static fn (MeasurementUnit $unit): ?int => $unit->getId(),
            $page['items'],
        )));

        return $this->render('admin/catalog/units/index.html.twig', [
            'page' => $page,
            'dimensionGroups' => $this->groupByDimension($page['items']),
            'dimensions' => MeasurementDimensionType::orderedCases(),
            'commercialUsageSummary' => $commercialItemRepository->summarizeUsageByMeasurementUnitIds($unitIds),
            'materialUsageSummary' => $materialRepository->summarizeUsageByMeasurementUnitIds($unitIds),
            'derivedUsageSummary' => $measurementUnitRepository->countActiveDerivedByBaseUnitIds($unitIds),
            'search' => $request->query->getString('q'),
            'status' => $status,
            'dimension' => $dimension?->value ?? 'all',
        ]);
    }

    #[Route('/ordenar', name: 'order', methods: ['GET'])]
    public function order(
        MeasurementUnitRepository $measurementUnitRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.units.manage');

        return $this->render('admin/catalog/units/order.html.twig', [
            'dimensionGroups' => $this->groupByDimension(
                $measurementUnitRepository->findActiveOrdered(),
            ),
        ]);
    }

    #[Route('/nueva', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        MeasurementUnitManager $measurementUnitManager,
        MeasurementUnitRepository $measurementUnitRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.units.manage');

        $data = new MeasurementUnitData();
        $form = $this->createForm(MeasurementUnitType::class, $data, [
            'base_units' => $measurementUnitRepository->findAvailableBaseUnits(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $measurementUnitManager->create($data, $this->getActor());

                $this->addFlash('success', 'Unidad de medida registrada correctamente.');

                return $this->redirectToRoute('admin_catalog_units_index');
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError('Ya existe otra unidad de medida con ese código o nombre.'));
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/catalog/units/form.html.twig', [
            'form' => $form,
            'unit' => null,
            'pageTitle' => 'Nueva unidad de medida',
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        MeasurementUnit $unit,
        MeasurementUnitManager $measurementUnitManager,
        MeasurementUnitRepository $measurementUnitRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.units.manage');

        $data = new MeasurementUnitData();
        $data->id = $unit->getId();
        $data->code = $unit->getCode();
        $data->name = $unit->getName();
        $data->symbol = $unit->getSymbol();
        $data->dimensionType = $unit->getDimension();
        $data->baseUnit = $unit->getBaseUnit();
        $data->conversionFactor = $unit->getConversionFactor();
        $data->decimalScale = $unit->getDecimalScale();
        $data->allowsFraction = $unit->allowsFraction();
        $data->displayOrder = $unit->getDisplayOrder();

        $form = $this->createForm(MeasurementUnitType::class, $data, [
            'base_units' => $measurementUnitRepository->findAvailableBaseUnits(
                editingUnit: $unit,
                selectedBaseUnit: $unit->getBaseUnit(),
            ),
            'lock_code' => strtoupper($unit->getCode()) === 'M2',
            'lock_conversion' => strtoupper($unit->getCode()) === 'M2',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $measurementUnitManager->update($unit, $data, $this->getActor());

                $this->addFlash('success', 'Unidad de medida actualizada correctamente.');

                return $this->redirectToRoute('admin_catalog_units_index');
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError('Ya existe otra unidad de medida con ese código o nombre.'));
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/catalog/units/form.html.twig', [
            'form' => $form,
            'unit' => $unit,
            'pageTitle' => 'Editar unidad de medida',
        ]);
    }

    #[Route('/{id}/estado', name: 'status', methods: ['POST'])]
    public function status(
        Request $request,
        MeasurementUnit $unit,
        MeasurementUnitManager $measurementUnitManager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.units.manage');

        if (!$this->isCsrfTokenValid(
            'catalog_unit_status_'.$unit->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $measurementUnitManager->setActive(
                $unit,
                !$unit->isActive(),
                $this->getActor(),
            );

            $this->addFlash(
                'success',
                $unit->isActive()
                    ? 'Unidad de medida reactivada correctamente.'
                    : 'Unidad de medida desactivada correctamente.',
            );
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute(
            'admin_catalog_units_index',
            $request->query->all(),
        );
    }

    #[Route('/reordenar', name: 'reorder', methods: ['POST'])]
    public function reorder(
        Request $request,
        MeasurementUnitManager $measurementUnitManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('catalog.units.manage');

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['message' => 'La solicitud no contiene datos válidos.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid('catalog_unit_reorder', (string) ($payload['_token'] ?? ''))) {
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
            $measurementUnitManager->reorderActive(
                (int) $movedId,
                $beforeId === null ? null : (int) $beforeId,
                $afterId === null ? null : (int) $afterId,
                $this->getActor(),
            );
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['message' => 'Orden actualizado correctamente.']);
    }

    /**
     * @param list<MeasurementUnit> $units
     * @return list<array{dimension: MeasurementDimensionType, units: list<MeasurementUnit>}>
     */
    private function groupByDimension(array $units): array
    {
        $byDimension = [];

        foreach ($units as $unit) {
            $byDimension[$unit->getDimensionType()][] = $unit;
        }

        $groups = [];

        foreach (MeasurementDimensionType::orderedCases() as $dimension) {
            $groupUnits = $byDimension[$dimension->value] ?? [];

            if ($groupUnits === []) {
                continue;
            }

            usort(
                $groupUnits,
                static fn (MeasurementUnit $left, MeasurementUnit $right): int =>
                    [$left->getDisplayOrder(), $left->getName(), $left->getId()]
                    <=> [$right->getDisplayOrder(), $right->getName(), $right->getId()],
            );

            $groups[] = [
                'dimension' => $dimension,
                'units' => $groupUnits,
            ];
        }

        return $groups;
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
