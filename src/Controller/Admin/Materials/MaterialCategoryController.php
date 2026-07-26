<?php

namespace App\Controller\Admin\Materials;

use App\Application\Materials\MaterialCategoryData;
use App\Application\Materials\MaterialCategoryManager;
use App\Entity\Materials\MaterialCategory;
use App\Entity\Users\User;
use App\Form\Admin\Materials\MaterialCategoryType;
use App\Repository\Materials\MaterialCategoryRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/materiales/categorias', name: 'admin_material_categories_')]
final class MaterialCategoryController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        MaterialCategoryRepository $materialCategoryRepository,
    ): Response {
        $this->denyAccessUnlessGranted('material_categories.view');

        $status = $request->query->getString('status', 'active');

        if (!in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'active';
        }

        $isActive = match ($status) {
            'active' => true,
            'inactive' => false,
            default => null,
        };

        return $this->render('admin/materials/categories/index.html.twig', [
            'page' => $materialCategoryRepository->paginateForAdministration(
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
        MaterialCategoryManager $materialCategoryManager,
    ): Response {
        $this->denyAccessUnlessGranted('material_categories.create');

        $data = new MaterialCategoryData();
        $form = $this->createForm(MaterialCategoryType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $materialCategoryManager->create($data, $this->getActor());

                $this->addFlash(
                    'success',
                    'Categoría de materiales registrada correctamente.',
                );

                return $this->redirectToRoute('admin_material_categories_index');
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError(
                    'El código o nombre ya está registrado para otra categoría.',
                ));
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/materials/categories/form.html.twig', [
            'form' => $form,
            'category' => null,
            'pageTitle' => 'Nueva categoría de materiales',
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        Request $request,
        MaterialCategory $category,
        MaterialCategoryManager $materialCategoryManager,
    ): Response {
        $this->denyAccessUnlessGranted('material_categories.update');

        $data = new MaterialCategoryData();
        $data->id = $category->getId();
        $data->code = $category->getCode();
        $data->name = $category->getName();
        $data->description = $category->getDescription();

        $form = $this->createForm(MaterialCategoryType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $materialCategoryManager->update(
                    $category,
                    $data,
                    $this->getActor(),
                );

                $this->addFlash(
                    'success',
                    'Categoría de materiales actualizada correctamente.',
                );

                return $this->redirectToRoute('admin_material_categories_index');
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError(
                    'El código o nombre ya está registrado para otra categoría.',
                ));
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/materials/categories/form.html.twig', [
            'form' => $form,
            'category' => $category,
            'pageTitle' => 'Editar categoría de materiales',
        ]);
    }

    #[Route('/{id}/estado', name: 'status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function status(
        Request $request,
        MaterialCategory $category,
        MaterialCategoryManager $materialCategoryManager,
    ): Response {
        $this->denyAccessUnlessGranted('material_categories.toggle_status');

        if (!$this->isCsrfTokenValid(
            'material_category_status_'.$category->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $materialCategoryManager->setActive(
                $category,
                !$category->isActive(),
                $this->getActor(),
            );

            $this->addFlash(
                'success',
                $category->isActive()
                    ? 'Categoría de materiales reactivada correctamente.'
                    : 'Categoría de materiales desactivada correctamente.',
            );
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute(
            'admin_material_categories_index',
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