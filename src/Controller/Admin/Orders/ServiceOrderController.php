<?php

declare(strict_types=1);

namespace App\Controller\Admin\Orders;

use App\Application\Orders\ServiceOrderItemOperationData;
use App\Application\Orders\ServiceOrderItemOperationEquipmentData;
use App\Application\Orders\ServiceOrderManager;
use App\Application\Orders\ServiceOrderPlanningData;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Quotations\Quotation;
use App\Entity\Users\User;
use App\Form\Admin\Orders\ServiceOrderItemOperationEquipmentType;
use App\Form\Admin\Orders\ServiceOrderItemOperationType;
use App\Form\Admin\Orders\ServiceOrderPlanningType;
use App\Repository\Equipment\EquipmentRepository;
use App\Repository\Operations\OperationRepository;
use App\Repository\Orders\ServiceOrderOperationPlanRepository;
use App\Repository\Orders\ServiceOrderRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/ordenes-servicio', name: 'admin_service_orders_')]
final class ServiceOrderController extends AbstractController
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(ServiceOrderRepository $serviceOrderRepository): Response
    {
        $this->denyAccessUnlessGranted('service_orders.view');

        return $this->render('admin/service_orders/index.html.twig', [
            'serviceOrders' => $serviceOrderRepository->findRecentForAdministration(),
        ]);
    }

    #[Route('/desde-cotizacion/{id}', name: 'create_from_quotation', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function createFromQuotation(
        Request $request,
        Quotation $quotation,
        ServiceOrderManager $serviceOrderManager,
        ServiceOrderRepository $serviceOrderRepository,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.create_from_quotation');

        if (!$this->isCsrfTokenValid(
            'service-order-create-from-quotation-'.$quotation->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('El token de seguridad para crear la orden no es válido.');
        }

        try {
            $serviceOrder = $serviceOrderManager->createFromAcceptedQuotation(
                $quotation,
                $this->authenticatedUser(),
            );

            $this->addFlash('success', sprintf('La orden de servicio %s se creó correctamente.', $serviceOrder->getFolio()));

            return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
        } catch (\DomainException $exception) {
            $this->addFlash('warning', $exception->getMessage());
        } catch (UniqueConstraintViolationException) {
            $existingOrder = $serviceOrderRepository->findOneBySourceQuotation($quotation);

            $this->addFlash('warning', 'Esta cotización ya fue convertida en una orden de servicio.');

            if ($existingOrder !== null) {
                return $this->redirectToRoute('admin_service_orders_show', ['id' => $existingOrder->getId()]);
            }
        }

        return $this->redirectToRoute('admin_quotations_show', ['id' => $quotation->getId()]);
    }

    #[Route('/{id}/planificacion', name: 'update_planning', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function updatePlanning(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderManager $serviceOrderManager,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.plan');

        $data = $this->planningData($serviceOrder);
        $form = $this->createPlanningForm($data, $serviceOrder);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'No fue posible guardar la fecha compromiso. Revisa los datos capturados.');

            return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
        }

        try {
            $serviceOrderManager->updatePlanning($serviceOrder, $data, $this->authenticatedUser());
            $this->addFlash('success', 'Fecha compromiso actualizada correctamente.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
    }

    #[Route('/{id}/partidas/{item}/operaciones', name: 'add_item_operation', requirements: ['id' => '\\d+', 'item' => '\\d+'], methods: ['POST'])]
    public function addItemOperation(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderItem $item,
        ServiceOrderManager $serviceOrderManager,
        OperationRepository $operationRepository,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.plan');

        $data = new ServiceOrderItemOperationData();
        $form = $this->createItemOperationForm($data, $serviceOrder, $item, $operationRepository->findActiveForServiceOrderPlanning());
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'No fue posible agregar la operación. Revisa los datos capturados.');

            return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
        }

        try {
            $serviceOrderManager->addOperationToItem($serviceOrder, $item, $data, $this->authenticatedUser());
            $this->addFlash('success', 'Operación agregada a la ruta de la partida.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('warning', 'Esta operación ya está registrada en la ruta de la partida.');
        }

        return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
    }

    #[Route('/{id}/operaciones/{plan}/equipo', name: 'assign_item_operation_equipment', requirements: ['id' => '\\d+', 'plan' => '\\d+'], methods: ['POST'])]
    public function assignItemOperationEquipment(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderOperationPlan $plan,
        ServiceOrderManager $serviceOrderManager,
        EquipmentRepository $equipmentRepository,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.plan');

        $data = new ServiceOrderItemOperationEquipmentData();
        $data->equipment = $plan->getEquipment();
        $form = $this->createEquipmentForm($data, $serviceOrder, $plan, $this->equipmentChoicesForPlan($plan, $equipmentRepository));
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'No fue posible actualizar el equipo. Revisa los datos capturados.');

            return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
        }

        try {
            $serviceOrderManager->assignEquipmentToOperation($serviceOrder, $plan, $data, $this->authenticatedUser());
            $this->addFlash('success', 'Equipo de la operación actualizado correctamente.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
    }

    #[Route('/{id}/operaciones/{plan}/retirar', name: 'remove_item_operation', requirements: ['id' => '\\d+', 'plan' => '\\d+'], methods: ['POST'])]
    public function removeItemOperation(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderOperationPlan $plan,
        ServiceOrderManager $serviceOrderManager,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.plan');

        if (!$this->isCsrfTokenValid(
            'service-order-operation-remove-'.$plan->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $serviceOrderManager->removeOperationFromItem($serviceOrder, $plan, $this->authenticatedUser());
            $this->addFlash('success', 'La operación fue retirada de la ruta; su historial se conserva en bitácora.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
    }

    #[Route('/{id}/partidas/{item}/operaciones/reordenar', name: 'reorder_item_operations', requirements: ['id' => '\\d+', 'item' => '\\d+'], methods: ['POST'])]
    public function reorderItemOperations(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderItem $item,
        ServiceOrderManager $serviceOrderManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('service_orders.plan');

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['message' => 'La solicitud no contiene datos válidos.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid(
            'service-order-operation-reorder-'.$item->getId(),
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
            $serviceOrderManager->reorderItemOperations($serviceOrder, $item, $movedId, $beforeId, $afterId, $this->authenticatedUser());
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['message' => 'Orden de operaciones actualizado correctamente.']);
    }

    #[Route('/{id}/marcar-planificada', name: 'mark_planned', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function markPlanned(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderManager $serviceOrderManager,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.plan');

        if (!$this->isCsrfTokenValid(
            'service-order-mark-planned-'.$serviceOrder->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $serviceOrderManager->markPlanned($serviceOrder, $this->authenticatedUser());
            $this->addFlash('success', 'La orden fue marcada como planificada. La ruta quedó bloqueada para conservar su trazabilidad.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(
        ServiceOrder $serviceOrder,
        OperationRepository $operationRepository,
        EquipmentRepository $equipmentRepository,
        ServiceOrderOperationPlanRepository $operationPlanRepository,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.view');

        $plansByItem = [];
        foreach ($operationPlanRepository->findActiveForServiceOrder($serviceOrder) as $plan) {
            $plansByItem[(int) $plan->getServiceOrderItem()->getId()][] = $plan;
        }

        $canPlan = $serviceOrder->isPendingPlanning() && $this->isGranted('service_orders.plan');
        $planningForm = null;
        $itemOperationForms = [];
        $equipmentForms = [];

        if ($canPlan) {
            $planningForm = $this->createPlanningForm($this->planningData($serviceOrder), $serviceOrder)->createView();
            $operations = $operationRepository->findActiveForServiceOrderPlanning();

            foreach ($serviceOrder->getItems() as $item) {
                $itemOperationForms[(int) $item->getId()] = $this->createItemOperationForm(
                    new ServiceOrderItemOperationData(),
                    $serviceOrder,
                    $item,
                    $operations,
                )->createView();

                foreach ($plansByItem[(int) $item->getId()] ?? [] as $plan) {
                    $equipmentData = new ServiceOrderItemOperationEquipmentData();
                    $equipmentData->equipment = $plan->getEquipment();
                    $equipmentForms[(int) $plan->getId()] = $this->createEquipmentForm(
                        $equipmentData,
                        $serviceOrder,
                        $plan,
                        $this->equipmentChoicesForPlan($plan, $equipmentRepository),
                    )->createView();
                }
            }
        }

        return $this->render('admin/service_orders/show.html.twig', [
            'serviceOrder' => $serviceOrder,
            'canPlan' => $canPlan,
            'planningForm' => $planningForm,
            'itemOperationForms' => $itemOperationForms,
            'equipmentForms' => $equipmentForms,
            'operationPlansByItem' => $plansByItem,
        ]);
    }

    private function planningData(ServiceOrder $serviceOrder): ServiceOrderPlanningData
    {
        $data = new ServiceOrderPlanningData();
        $data->commitmentDate = $serviceOrder->getCommitmentDate()?->format('Y-m-d');

        return $data;
    }

    private function createPlanningForm(ServiceOrderPlanningData $data, ServiceOrder $serviceOrder): FormInterface
    {
        return $this->createForm(ServiceOrderPlanningType::class, $data, [
            'csrf_token_id' => 'service-order-planning-'.$serviceOrder->getId(),
        ]);
    }

    /** @param list<\App\Entity\Operations\Operation> $operations */
    private function createItemOperationForm(
        ServiceOrderItemOperationData $data,
        ServiceOrder $serviceOrder,
        ServiceOrderItem $item,
        array $operations,
    ): FormInterface {
        return $this->formFactory->createNamed(
            'service_order_item_operation_'.$item->getId(),
            ServiceOrderItemOperationType::class,
            $data,
            [
                'operations' => $operations,
                'csrf_token_id' => 'service-order-item-operation-'.$serviceOrder->getId().'-'.$item->getId(),
            ],
        );
    }

    /** @param list<\App\Entity\Equipment\Equipment> $equipment */
    private function createEquipmentForm(
        ServiceOrderItemOperationEquipmentData $data,
        ServiceOrder $serviceOrder,
        ServiceOrderOperationPlan $plan,
        array $equipment,
    ): FormInterface {
        return $this->formFactory->createNamed(
            'service_order_operation_equipment_'.$plan->getId(),
            ServiceOrderItemOperationEquipmentType::class,
            $data,
            [
                'equipment' => $equipment,
                'csrf_token_id' => 'service-order-operation-equipment-'.$serviceOrder->getId().'-'.$plan->getId(),
            ],
        );
    }

    /** @return list<\App\Entity\Equipment\Equipment> */
    private function equipmentChoicesForPlan(ServiceOrderOperationPlan $plan, EquipmentRepository $equipmentRepository): array
    {
        $equipment = $equipmentRepository->findAvailableForFutureExecution($plan->getOperation());
        $selectedEquipment = $plan->getEquipment();
        if ($selectedEquipment !== null && !in_array($selectedEquipment, $equipment, true)) {
            $equipment[] = $selectedEquipment;
        }

        return $equipment;
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

    private function authenticatedUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}