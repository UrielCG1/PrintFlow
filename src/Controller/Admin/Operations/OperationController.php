<?php

declare(strict_types=1);

namespace App\Controller\Admin\Operations;

use App\Application\Operations\OperationData;
use App\Application\Operations\OperationManager;
use App\Entity\Operations\Operation;
use App\Entity\Operations\OperationArea;
use App\Entity\Users\User;
use App\Form\Admin\Operations\OperationType;
use App\Repository\Operations\OperationAreaRepository;
use App\Repository\Operations\OperationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/operaciones', name: 'admin_operations_')]
final class OperationController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        OperationRepository $operationRepository,
        OperationAreaRepository $operationAreaRepository,
    ): Response {
        $this->denyAccessUnlessGranted('operations.view');

        $status = $request->query->getString('status', 'active');
        if (!in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'active';
        }

        $areaId = $this->queryOptionalPositiveId($request, 'area');
        $selectedArea = $areaId !== null ? $operationAreaRepository->find($areaId) : null;

        return $this->render('admin/operations/index.html.twig', [
            'page' => $operationRepository->paginateForAdministration(
                search: $request->query->getString('q'),
                operationArea: $selectedArea,
                isActive: match ($status) {
                    'active' => true,
                    'inactive' => false,
                    default => null,
                },
                page: $request->query->getInt('page', 1),
            ),
            'operationAreas' => $operationAreaRepository->findAllOrdered(),
            'selectedArea' => $selectedArea,
            'search' => $request->query->getString('q'),
            'status' => $status,
        ]);
    }

    #[Route('/nueva', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        OperationManager $operationManager,
        OperationAreaRepository $operationAreaRepository,
    ): Response {
        $this->denyAccessUnlessGranted('operations.create');

        $data = new OperationData();
        $form = $this->createOperationForm($data, $operationAreaRepository->findAvailableForOperationForm());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $operationManager->create($data, $this->getActor());
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError('El código o nombre de la operación ya está registrado.'));

                return $this->render('admin/operations/form.html.twig', [
                    'form' => $form,
                    'operation' => null,
                    'pageTitle' => 'Nueva operación',
                ]);
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));

                return $this->render('admin/operations/form.html.twig', [
                    'form' => $form,
                    'operation' => null,
                    'pageTitle' => 'Nueva operación',
                ]);
            }

            $this->addFlash('success', 'Operación registrada correctamente.');

            return $this->redirectToRoute('admin_operations_index');
        }

        return $this->render('admin/operations/form.html.twig', [
            'form' => $form,
            'operation' => null,
            'pageTitle' => 'Nueva operación',
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\\d+'])]
    public function edit(
        Request $request,
        Operation $operation,
        OperationManager $operationManager,
        OperationAreaRepository $operationAreaRepository,
    ): Response {
        $this->denyAccessUnlessGranted('operations.update');

        $data = new OperationData();
        $data->id = $operation->getId();
        $data->operationArea = $operation->getOperationArea();
        $data->code = $operation->getCode();
        $data->name = $operation->getName();
        $data->description = $operation->getDescription();

        $form = $this->createOperationForm(
            $data,
            $operationAreaRepository->findAvailableForOperationForm($operation->getOperationArea()),
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $operationManager->update($operation, $data, $this->getActor());
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError('El código o nombre de la operación ya está registrado.'));

                return $this->render('admin/operations/form.html.twig', [
                    'form' => $form,
                    'operation' => $operation,
                    'pageTitle' => 'Editar operación',
                ]);
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));

                return $this->render('admin/operations/form.html.twig', [
                    'form' => $form,
                    'operation' => $operation,
                    'pageTitle' => 'Editar operación',
                ]);
            }

            $this->addFlash('success', 'Operación actualizada correctamente.');

            return $this->redirectToRoute('admin_operations_index');
        }

        return $this->render('admin/operations/form.html.twig', [
            'form' => $form,
            'operation' => $operation,
            'pageTitle' => 'Editar operación',
        ]);
    }

    #[Route('/{id}/estado', name: 'status', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function status(
        Request $request,
        Operation $operation,
        OperationManager $operationManager,
    ): Response {
        $this->denyAccessUnlessGranted('operations.toggle_status');

        if (!$this->isCsrfTokenValid(
            'operation_status_'.$operation->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $operationManager->setActive($operation, !$operation->isActive(), $this->getActor());
            $this->addFlash(
                'success',
                $operation->isActive()
                    ? 'Operación reactivada correctamente.'
                    : 'Operación desactivada correctamente.',
            );
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_operations_index', $request->query->all());
    }

    #[Route('/reordenar/{id}', name: 'reorder', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function reorder(
        Request $request,
        OperationArea $operationArea,
        OperationManager $operationManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('operations.reorder');

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['message' => 'La solicitud no contiene datos válidos.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid(
            'operation_reorder_'.$operationArea->getId(),
            (string) ($payload['_token'] ?? ''),
        )) {
            return $this->json(['message' => 'La solicitud no es válida.'], Response::HTTP_FORBIDDEN);
        }

        $movedId = $this->optionalPositiveId($payload['movedId'] ?? null);
        $beforeId = $this->optionalPositiveId($payload['beforeId'] ?? null);
        $afterId = $this->optionalPositiveId($payload['afterId'] ?? null);

        if ($movedId === null || (($payload['beforeId'] ?? null) !== null && $beforeId === null) || (($payload['afterId'] ?? null) !== null && $afterId === null)) {
            return $this->json(['message' => 'La posición recibida no es válida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $operationManager->reorderActive($operationArea, $movedId, $beforeId, $afterId, $this->getActor());
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['message' => 'Orden actualizado correctamente.']);
    }

    /** @param list<OperationArea> $operationAreas */
    private function createOperationForm(OperationData $data, array $operationAreas): \Symfony\Component\Form\FormInterface
    {
        return $this->createForm(OperationType::class, $data, [
            'operation_areas' => $operationAreas,
        ]);
    }

    private function queryOptionalPositiveId(Request $request, string $key): ?int
    {
        return $this->optionalPositiveId($request->query->getString($key, ''));
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