<?php

namespace App\Controller\Admin\Catalog;

use App\Application\Catalog\MeasurementUnitData;
use App\Application\Catalog\MeasurementUnitManager;
use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Users\User;
use App\Form\Admin\Catalog\MeasurementUnitType;
use App\Repository\Catalog\MeasurementUnitRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

#[Route('/admin/catalogo/unidades', name: 'admin_catalog_units_')]
final class MeasurementUnitController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        MeasurementUnitRepository $measurementUnitRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.view');

        $status = $request->query->getString('status', 'active');
        $isActive = match ($status) {
            'active' => true,
            'inactive' => false,
            default => null,
        };

        return $this->render('admin/catalog/units/index.html.twig', [
            'page' => $measurementUnitRepository->paginateForAdministration(
                search: $request->query->getString('q'),
                isActive: $isActive,
                page: $request->query->getInt('page', 1),
            ),
            'search' => $request->query->getString('q'),
            'status' => $status,
        ]);
    }

    #[Route('/nueva', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        MeasurementUnitManager $measurementUnitManager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.units.manage');

        $data = new MeasurementUnitData();
        $form = $this->createForm(MeasurementUnitType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $measurementUnitManager->create($data, $this->getActor());

            $this->addFlash('success', 'Unidad de medida registrada correctamente.');

            return $this->redirectToRoute('admin_catalog_units_index');
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
    ): Response {
        $this->denyAccessUnlessGranted('catalog.units.manage');

        $data = new MeasurementUnitData();
        $data->id = $unit->getId();
        $data->code = $unit->getCode();
        $data->name = $unit->getName();
        $data->displayOrder = $unit->getDisplayOrder();

        $form = $this->createForm(MeasurementUnitType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $measurementUnitManager->update($unit, $data, $this->getActor());

            $this->addFlash('success', 'Unidad de medida actualizada correctamente.');

            return $this->redirectToRoute('admin_catalog_units_index');
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

    private function getActor(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}