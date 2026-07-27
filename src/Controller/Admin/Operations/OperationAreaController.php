<?php

declare(strict_types=1);

namespace App\Controller\Admin\Operations;

use App\Application\Operations\OperationAreaData;
use App\Application\Operations\OperationAreaManager;
use App\Entity\Operations\OperationArea;
use App\Entity\Users\User;
use App\Form\Admin\Operations\OperationAreaType;
use App\Repository\Operations\OperationAreaRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/operaciones/areas', name: 'admin_operation_areas_')]
final class OperationAreaController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, OperationAreaRepository $operationAreaRepository): Response
    {
        $this->denyAccessUnlessGranted('operation_areas.view');

        $status = $request->query->getString('status', 'active');
        if (!in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'active';
        }

        return $this->render('admin/operations/areas/index.html.twig', [
            'page' => $operationAreaRepository->paginateForAdministration(
                search: $request->query->getString('q'),
                isActive: match ($status) {
                    'active' => true,
                    'inactive' => false,
                    default => null,
                },
                page: $request->query->getInt('page', 1),
            ),
            'search' => $request->query->getString('q'),
            'status' => $status,
        ]);
    }

    #[Route('/nueva', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, OperationAreaManager $operationAreaManager): Response
    {
        $this->denyAccessUnlessGranted('operation_areas.create');

        $data = new OperationAreaData();
        $form = $this->createForm(OperationAreaType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $operationAreaManager->create($data, $this->getActor());
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError('El código o nombre del área ya está registrado.'));

                return $this->render('admin/operations/areas/form.html.twig', [
                    'form' => $form,
                    'operationArea' => null,
                    'pageTitle' => 'Nueva área operativa',
                ]);
            }

            $this->addFlash('success', 'Área operativa registrada correctamente.');

            return $this->redirectToRoute('admin_operation_areas_index');
        }

        return $this->render('admin/operations/areas/form.html.twig', [
            'form' => $form,
            'operationArea' => null,
            'pageTitle' => 'Nueva área operativa',
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\\d+'])]
    public function edit(
        Request $request,
        OperationArea $operationArea,
        OperationAreaManager $operationAreaManager,
    ): Response {
        $this->denyAccessUnlessGranted('operation_areas.update');

        $data = new OperationAreaData();
        $data->id = $operationArea->getId();
        $data->code = $operationArea->getCode();
        $data->name = $operationArea->getName();
        $data->description = $operationArea->getDescription();
        $data->displayOrder = $operationArea->getDisplayOrder();

        $form = $this->createForm(OperationAreaType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $operationAreaManager->update($operationArea, $data, $this->getActor());
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError('El código o nombre del área ya está registrado.'));

                return $this->render('admin/operations/areas/form.html.twig', [
                    'form' => $form,
                    'operationArea' => $operationArea,
                    'pageTitle' => 'Editar área operativa',
                ]);
            }

            $this->addFlash('success', 'Área operativa actualizada correctamente.');

            return $this->redirectToRoute('admin_operation_areas_index');
        }

        return $this->render('admin/operations/areas/form.html.twig', [
            'form' => $form,
            'operationArea' => $operationArea,
            'pageTitle' => 'Editar área operativa',
        ]);
    }

    #[Route('/{id}/estado', name: 'status', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function status(
        Request $request,
        OperationArea $operationArea,
        OperationAreaManager $operationAreaManager,
    ): Response {
        $this->denyAccessUnlessGranted('operation_areas.toggle_status');

        if (!$this->isCsrfTokenValid(
            'operation_area_status_'.$operationArea->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $operationAreaManager->setActive(
                $operationArea,
                !$operationArea->isActive(),
                $this->getActor(),
            );
            $this->addFlash(
                'success',
                $operationArea->isActive()
                    ? 'Área operativa reactivada correctamente.'
                    : 'Área operativa desactivada correctamente.',
            );
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_operation_areas_index', $request->query->all());
    }

    #[Route('/reordenar', name: 'reorder', methods: ['POST'])]
    public function reorder(Request $request, OperationAreaManager $operationAreaManager): JsonResponse
    {
        $this->denyAccessUnlessGranted('operation_areas.reorder');

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['message' => 'La solicitud no contiene datos válidos.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid('operation_area_reorder', (string) ($payload['_token'] ?? ''))) {
            return $this->json(['message' => 'La solicitud no es válida.'], Response::HTTP_FORBIDDEN);
        }

        $movedId = $this->optionalPositiveId($payload['movedId'] ?? null);
        $beforeId = $this->optionalPositiveId($payload['beforeId'] ?? null);
        $afterId = $this->optionalPositiveId($payload['afterId'] ?? null);

        if ($movedId === null || (($payload['beforeId'] ?? null) !== null && $beforeId === null) || (($payload['afterId'] ?? null) !== null && $afterId === null)) {
            return $this->json(['message' => 'La posición recibida no es válida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $operationAreaManager->reorderActive($movedId, $beforeId, $afterId, $this->getActor());
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['message' => 'Orden actualizado correctamente.']);
    }

    private function optionalPositiveId(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
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