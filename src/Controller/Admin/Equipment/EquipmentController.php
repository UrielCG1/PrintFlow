<?php

declare(strict_types=1);

namespace App\Controller\Admin\Equipment;

use App\Application\Equipment\EquipmentData;
use App\Application\Equipment\EquipmentManager;
use App\Entity\Equipment\Equipment;
use App\Entity\Users\User;
use App\Enum\Equipment\EquipmentStatus;
use App\Form\Admin\Equipment\EquipmentType;
use App\Repository\Equipment\EquipmentRepository;
use App\Repository\Operations\OperationAreaRepository;
use App\Repository\Operations\OperationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/equipos', name: 'admin_equipment_')]
final class EquipmentController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        EquipmentRepository $equipmentRepository,
        OperationAreaRepository $operationAreaRepository,
        OperationRepository $operationRepository,
    ): Response {
        $this->denyAccessUnlessGranted('equipment.view');

        $status = $this->resolveStatusFilter($request->query->getString('status', 'available'));
        $areaId = $this->queryOptionalPositiveId($request, 'area');
        $operationId = $this->queryOptionalPositiveId($request, 'operation');
        $selectedArea = $areaId !== null ? $operationAreaRepository->find($areaId) : null;
        $selectedOperation = $operationId !== null ? $operationRepository->find($operationId) : null;

        return $this->render('admin/equipment/index.html.twig', [
            'page' => $equipmentRepository->paginateForAdministration(
                search: $request->query->getString('q'),
                operationArea: $selectedArea,
                operation: $selectedOperation,
                status: $status === 'all' ? null : EquipmentStatus::from($status),
                page: $this->queryPositiveInt($request, 'page', 1),
            ),
            'operationAreas' => $operationAreaRepository->findAllOrdered(),
            'operations' => $operationRepository->findAllOrdered(),
            'selectedArea' => $selectedArea,
            'selectedOperation' => $selectedOperation,
            'search' => $request->query->getString('q'),
            'status' => $status,
        ]);
    }

    #[Route('/nuevo', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EquipmentManager $equipmentManager,
        OperationRepository $operationRepository,
    ): Response {
        $this->denyAccessUnlessGranted('equipment.create');

        $data = new EquipmentData();
        $form = $this->createEquipmentForm($data, $operationRepository->findAvailableForEquipmentForm());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $equipmentManager->create($data, $this->getActor());
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError('El código o número de serie ya está registrado.'));

                return $this->renderEquipmentForm($form, null, 'Nuevo equipo');
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));

                return $this->renderEquipmentForm($form, null, 'Nuevo equipo');
            }

            $this->addFlash('success', 'Equipo registrado correctamente.');

            return $this->redirectToRoute('admin_equipment_index');
        }

        return $this->renderEquipmentForm($form, null, 'Nuevo equipo');
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\\d+'])]
    public function edit(
        Request $request,
        Equipment $equipment,
        EquipmentManager $equipmentManager,
        OperationRepository $operationRepository,
    ): Response {
        $this->denyAccessUnlessGranted('equipment.update');

        $data = new EquipmentData();
        $data->id = $equipment->getId();
        $data->primaryOperation = $equipment->getPrimaryOperation();
        $data->code = $equipment->getCode();
        $data->name = $equipment->getName();
        $data->technology = $equipment->getTechnology();
        $data->brand = $equipment->getBrand();
        $data->model = $equipment->getModel();
        $data->serialNumber = $equipment->getSerialNumber();
        $data->usableWidthCm = $equipment->getUsableWidthCm();
        $data->technicalCapacity = $equipment->getTechnicalCapacity();
        $data->colorConfiguration = $equipment->getColorConfiguration();
        $data->observations = $equipment->getObservations();

        $form = $this->createEquipmentForm(
            $data,
            $operationRepository->findAvailableForEquipmentForm($equipment->getPrimaryOperation()),
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $equipmentManager->update($equipment, $data, $this->getActor());
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError('El código o número de serie ya está registrado.'));

                return $this->renderEquipmentForm($form, $equipment, 'Editar equipo');
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));

                return $this->renderEquipmentForm($form, $equipment, 'Editar equipo');
            }

            $this->addFlash('success', 'Equipo actualizado correctamente.');

            return $this->redirectToRoute('admin_equipment_index');
        }

        return $this->renderEquipmentForm($form, $equipment, 'Editar equipo');
    }

    #[Route('/{id}/estado', name: 'status', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function status(
        Request $request,
        Equipment $equipment,
        EquipmentManager $equipmentManager,
    ): Response {
        $this->denyAccessUnlessGranted('equipment.change_status');

        if (!$this->isCsrfTokenValid(
            'equipment_status_'.$equipment->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        $targetStatus = EquipmentStatus::tryFrom($request->request->getString('status'));
        if ($targetStatus === null) {
            $this->addFlash('error', 'El estado solicitado no es válido.');

            return $this->redirectToRoute('admin_equipment_index', $request->query->all());
        }

        try {
            $equipmentManager->changeStatus($equipment, $targetStatus, $this->getActor());
            $this->addFlash('success', sprintf('Equipo actualizado a estado: %s.', $targetStatus->label()));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_equipment_index', $request->query->all());
    }

    /** @param list<\App\Entity\Operations\Operation> $operations */
    private function createEquipmentForm(EquipmentData $data, array $operations): \Symfony\Component\Form\FormInterface
    {
        return $this->createForm(EquipmentType::class, $data, ['operations' => $operations]);
    }

    private function renderEquipmentForm(\Symfony\Component\Form\FormInterface $form, ?Equipment $equipment, string $pageTitle): Response
    {
        return $this->render('admin/equipment/form.html.twig', [
            'form' => $form,
            'equipment' => $equipment,
            'pageTitle' => $pageTitle,
        ]);
    }

    private function resolveStatusFilter(string $status): string
    {
        return in_array($status, ['available', 'maintenance', 'inactive', 'all'], true)
            ? $status
            : 'available';
    }

    private function queryOptionalPositiveId(Request $request, string $key): ?int
    {
        return $this->optionalPositiveId($request->query->getString($key, ''));
    }

    private function queryPositiveInt(Request $request, string $key, int $default): int
    {
        return $this->optionalPositiveId($request->query->getString($key, '')) ?? $default;
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