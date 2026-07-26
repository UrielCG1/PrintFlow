<?php

namespace App\Controller\Admin\Materials;

use App\Application\Materials\MaterialData;
use App\Application\Materials\MaterialManager;
use App\Entity\Materials\Material;
use App\Entity\Users\User;
use App\Form\Admin\Materials\MaterialType;
use App\Repository\Catalog\MeasurementUnitRepository;
use App\Repository\Materials\MaterialCategoryRepository;
use App\Repository\Materials\MaterialRepository;
use App\Repository\Suppliers\SupplierRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/materiales', name: 'admin_materials_')]
final class MaterialController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        MaterialRepository $materialRepository,
        MaterialCategoryRepository $materialCategoryRepository,
        MeasurementUnitRepository $measurementUnitRepository,
        SupplierRepository $supplierRepository,
    ): Response {
        $this->denyAccessUnlessGranted('materials.view');

        $status = $request->query->getString('status', 'active');

        if (!in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'active';
        }

        $isActive = match ($status) {
            'active' => true,
            'inactive' => false,
            default => null,
        };

        $categoryId = $this->getOptionalPositiveId($request, 'category');
        $measurementUnitId = $this->getOptionalPositiveId($request, 'unit');
        $primarySupplierId = $this->getOptionalPositiveId($request, 'supplier');

        return $this->render('admin/materials/index.html.twig', [
            'page' => $materialRepository->paginateForAdministration(
                search: $request->query->getString('q'),
                isActive: $isActive,
                categoryId: $categoryId,
                measurementUnitId: $measurementUnitId,
                primarySupplierId: $primarySupplierId,
                page: $request->query->getInt('page', 1),
            ),
            'search' => $request->query->getString('q'),
            'status' => $status,
            'categoryId' => $categoryId,
            'measurementUnitId' => $measurementUnitId,
            'primarySupplierId' => $primarySupplierId,
            'categories' => $materialCategoryRepository->findAvailableForMaterialForm(),
            'measurementUnits' => $measurementUnitRepository->findAvailableForMaterialForm(),
            'suppliers' => $supplierRepository->findAvailableForMaterialForm(),
        ]);
    }

    #[Route('/nuevo', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        MaterialManager $materialManager,
        MaterialCategoryRepository $materialCategoryRepository,
        MeasurementUnitRepository $measurementUnitRepository,
        SupplierRepository $supplierRepository,
    ): Response {
        $this->denyAccessUnlessGranted('materials.create');

        $data = new MaterialData();

        $form = $this->createMaterialForm(
            $data,
            $materialCategoryRepository,
            $measurementUnitRepository,
            $supplierRepository,
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $materialManager->create($data, $this->getActor());

                $this->addFlash('success', 'Material registrado correctamente.');

                return $this->redirectToRoute('admin_materials_index');
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError(
                    'Ya existe otro material con este código.',
                ));
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/materials/form.html.twig', [
            'form' => $form,
            'material' => null,
            'pageTitle' => 'Nuevo material',
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        Request $request,
        Material $material,
        MaterialManager $materialManager,
        MaterialCategoryRepository $materialCategoryRepository,
        MeasurementUnitRepository $measurementUnitRepository,
        SupplierRepository $supplierRepository,
    ): Response {
        $this->denyAccessUnlessGranted('materials.update');

        $data = new MaterialData();
        $data->id = $material->getId();
        $data->code = $material->getCode();
        $data->name = $material->getName();
        $data->description = $material->getDescription();
        $data->category = $material->getCategory();
        $data->measurementUnit = $material->getMeasurementUnit();
        $data->primarySupplier = $material->getPrimarySupplier();
        $data->referenceCost = $material->getReferenceCost();
        $data->minimumStock = $material->getMinimumStock();
        $data->notes = $material->getNotes();

        $form = $this->createMaterialForm(
            $data,
            $materialCategoryRepository,
            $measurementUnitRepository,
            $supplierRepository,
            $material,
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $materialManager->update($material, $data, $this->getActor());

                $this->addFlash('success', 'Material actualizado correctamente.');

                return $this->redirectToRoute('admin_materials_index');
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError(
                    'Ya existe otro material con este código.',
                ));
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/materials/form.html.twig', [
            'form' => $form,
            'material' => $material,
            'pageTitle' => 'Editar material',
        ]);
    }

    #[Route('/{id}/estado', name: 'status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function status(
        Request $request,
        Material $material,
        MaterialManager $materialManager,
    ): Response {
        $this->denyAccessUnlessGranted('materials.toggle_status');

        if (!$this->isCsrfTokenValid(
            'material_status_'.$material->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        $materialManager->setActive(
            $material,
            !$material->isActive(),
            $this->getActor(),
        );

        $this->addFlash(
            'success',
            $material->isActive()
                ? 'Material reactivado correctamente.'
                : 'Material desactivado correctamente.',
        );

        return $this->redirectToRoute(
            'admin_materials_index',
            $request->query->all(),
        );
    }

    private function createMaterialForm(
        MaterialData $data,
        MaterialCategoryRepository $materialCategoryRepository,
        MeasurementUnitRepository $measurementUnitRepository,
        SupplierRepository $supplierRepository,
        ?Material $material = null,
    ): \Symfony\Component\Form\FormInterface {
        return $this->createForm(MaterialType::class, $data, [
            'categories' => $materialCategoryRepository
                ->findAvailableForMaterialForm($material?->getCategory()),
            'measurement_units' => $measurementUnitRepository
                ->findAvailableForMaterialForm($material?->getMeasurementUnit()),
            'suppliers' => $supplierRepository
                ->findAvailableForMaterialForm($material?->getPrimarySupplier()),
        ]);
    }

    private function getActor(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function getOptionalPositiveId(Request $request, string $key): ?int
    {
        $value = trim($request->query->getString($key, ''));

        if ('' === $value || !ctype_digit($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}