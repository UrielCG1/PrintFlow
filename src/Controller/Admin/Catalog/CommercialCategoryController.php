<?php

namespace App\Controller\Admin\Catalog;

use App\Application\Catalog\CommercialCategoryData;
use App\Application\Catalog\CommercialCategoryManager;
use App\Entity\Catalog\CommercialCategory;
use App\Entity\Users\User;
use App\Form\Admin\Catalog\CommercialCategoryType;
use App\Repository\Catalog\CommercialCategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

#[Route('/admin/catalogo/categorias', name: 'admin_catalog_categories_')]
final class CommercialCategoryController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        CommercialCategoryRepository $commercialCategoryRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.view');

        $status = $request->query->getString('status', 'active');
        $isActive = match ($status) {
            'active' => true,
            'inactive' => false,
            default => null,
        };

        return $this->render('admin/catalog/categories/index.html.twig', [
            'page' => $commercialCategoryRepository->paginateForAdministration(
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
        CommercialCategoryManager $commercialCategoryManager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.categories.manage');

        $data = new CommercialCategoryData();
        $form = $this->createForm(CommercialCategoryType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $commercialCategoryManager->create($data, $this->getActor());

            $this->addFlash('success', 'Categoría comercial registrada correctamente.');

            return $this->redirectToRoute('admin_catalog_categories_index');
        }

        return $this->render('admin/catalog/categories/form.html.twig', [
            'form' => $form,
            'category' => null,
            'pageTitle' => 'Nueva categoría comercial',
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        CommercialCategory $category,
        CommercialCategoryManager $commercialCategoryManager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.categories.manage');

        $data = new CommercialCategoryData();
        $data->id = $category->getId();
        $data->code = $category->getCode();
        $data->name = $category->getName();
        $data->description = $category->getDescription();
        $data->displayOrder = $category->getDisplayOrder();

        $form = $this->createForm(CommercialCategoryType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $commercialCategoryManager->update($category, $data, $this->getActor());

            $this->addFlash('success', 'Categoría comercial actualizada correctamente.');

            return $this->redirectToRoute('admin_catalog_categories_index');
        }

        return $this->render('admin/catalog/categories/form.html.twig', [
            'form' => $form,
            'category' => $category,
            'pageTitle' => 'Editar categoría comercial',
        ]);
    }

    #[Route('/{id}/estado', name: 'status', methods: ['POST'])]
    public function status(
        Request $request,
        CommercialCategory $category,
        CommercialCategoryManager $commercialCategoryManager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.categories.manage');

        if (!$this->isCsrfTokenValid(
            'catalog_category_status_'.$category->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $commercialCategoryManager->setActive(
                $category,
                !$category->isActive(),
                $this->getActor(),
            );

            $this->addFlash(
                'success',
                $category->isActive()
                    ? 'Categoría comercial reactivada correctamente.'
                    : 'Categoría comercial desactivada correctamente.',
            );
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute(
            'admin_catalog_categories_index',
            $request->query->all(),
        );
    }

    #[Route('/reordenar', name: 'reorder', methods: ['POST'])]
    public function reorder(
        Request $request,
        CommercialCategoryManager $commercialCategoryManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('catalog.categories.manage');

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['message' => 'La solicitud no contiene datos válidos.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid('catalog_category_reorder', (string) ($payload['_token'] ?? ''))) {
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
            $commercialCategoryManager->reorderActive(
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