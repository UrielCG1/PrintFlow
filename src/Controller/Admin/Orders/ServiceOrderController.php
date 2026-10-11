<?php

declare(strict_types=1);

namespace App\Controller\Admin\Orders;

use App\Application\Orders\ServiceOrderItemOperationData;
use App\Application\Orders\ServiceOrderItemOperationEquipmentData;
use App\Application\Orders\ServiceOrderManager;
use App\Application\Orders\ServiceOrderPlanningData;
use App\Application\Production\ProductionCommitmentForecaster;
use App\Application\Production\ProductionLoadResolver;
use App\Application\Production\ProductionPlanManager;
use App\Application\Production\ProductionPriorityRescheduler;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Quotations\Quotation;
use App\Entity\Users\User;
use App\Enum\Orders\ProductionPriority;
use App\Enum\Equipment\ProductionLoadUnit;
use App\Form\Admin\Orders\ServiceOrderItemOperationEquipmentType;
use App\Form\Admin\Orders\ServiceOrderItemOperationType;
use App\Form\Admin\Orders\ServiceOrderPlanningType;
use App\Repository\Equipment\EquipmentRepository;
use App\Repository\Operations\OperationRepository;
use App\Repository\Orders\ServiceOrderOperationPlanRepository;
use App\Repository\Orders\ServiceOrderRepository;
use App\Repository\Production\ProductionReservationRepository;
use App\Repository\Users\UserRepository;
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
        $this->denyAccessUnlessGranted('service_orders.commitment.manage');

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

    #[Route('/{id}/preparar-automaticamente', name: 'prepare_automatically', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function prepareAutomatically(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderManager $serviceOrderManager,
        ProductionPlanManager $productionPlanManager,
        ServiceOrderOperationPlanRepository $operationPlanRepository,
        EquipmentRepository $equipmentRepository,
        ProductionLoadResolver $loadResolver,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.plan');
        if (!$this->isCsrfTokenValid('service-order-auto-prepare-'.$serviceOrder->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }
        if (!$serviceOrder->isPendingPlanning()) {
            throw $this->createAccessDeniedException('La orden ya no está pendiente de planificación.');
        }

        $equipmentAssigned = 0;
        $estimatesCalculated = 0;
        $pending = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City'));
        foreach ($operationPlanRepository->findActiveForServiceOrder($serviceOrder) as $plan) {
            $item = $plan->getServiceOrderItem();
            $label = sprintf('%s · partida %s', $plan->getOperationSnapshot()['name'] ?? $plan->getOperation()->getName(), $item->getDisplayLineNumber());
            if ($item->getPlanningIssue() !== null || $plan->getBlockedReason() !== null) {
                $pending[] = $label.': requiere revisión antes de planificar.';
                continue;
            }

            if ($plan->getEquipment() === null) {
                $choices = $equipmentRepository->findAvailableForFutureExecution($plan->getOperation());
                if (count($choices) === 1) {
                    $data = new ServiceOrderItemOperationEquipmentData();
                    $data->equipment = $choices[0];
                    try {
                        $serviceOrderManager->assignEquipmentToOperation($serviceOrder, $plan, $data, $this->authenticatedUser());
                        ++$equipmentAssigned;
                    } catch (\DomainException $exception) {
                        $pending[] = $label.': '.$exception->getMessage();
                    }
                } else {
                    $pending[] = $label.($choices === [] ? ': no hay máquina compatible disponible.' : ': elige una de las máquinas compatibles.');
                    continue;
                }
            }

            if ($plan->getEstimatedMinutes() !== null) {
                continue;
            }

            $equipment = $plan->getEquipment();
            $unit = $equipment?->getCapacityUnit()?->loadUnit();
            $load = $unit !== null ? $loadResolver->automaticLoad($item, $unit) : null;
            if ($equipment !== null && $unit !== null && $load !== null && $equipment->hasValidatedCapacityAt($now)) {
                try {
                    $productionPlanManager->estimate($plan, null, $unit, null, '', $this->authenticatedUser());
                    ++$estimatesCalculated;
                } catch (\DomainException|\InvalidArgumentException $exception) {
                    $pending[] = $label.': '.$exception->getMessage();
                }
                continue;
            }

            if ($unit === ProductionLoadUnit::CUT_CENTIMETERS) {
                $pending[] = $label.': captura la trayectoria real de corte en cm.';
            } elseif ($unit === ProductionLoadUnit::LINEAR_METERS) {
                $pending[] = $label.': captura los metros lineales reales según el acomodo.';
            } elseif ($unit === ProductionLoadUnit::SQUARE_METERS && $load === null) {
                $pending[] = $label.': faltan dimensiones válidas o la carga física en m².';
            } else {
                $pending[] = $label.': la máquina requiere validar capacidad o capturar minutos manuales justificados.';
            }
        }

        if ($equipmentAssigned > 0 || $estimatesCalculated > 0) {
            $this->addFlash('success', sprintf('Preparación automática: %d máquina(s) asignada(s) y %d estimación(es) calculada(s).', $equipmentAssigned, $estimatesCalculated));
        }
        foreach (array_unique($pending) as $message) {
            $this->addFlash('warning', $message);
        }
        if ($equipmentAssigned === 0 && $estimatesCalculated === 0 && $pending === []) {
            $this->addFlash('info', 'La orden ya no tiene datos automáticos pendientes.');
        }

        return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
    }

    #[Route('/{id}/reservar-plan-completo', name: 'reserve_full_plan', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function reserveFullPlan(
        Request $request,
        ServiceOrder $serviceOrder,
        ProductionPlanManager $productionPlanManager,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.plan');
        if (!$this->isCsrfTokenValid('service-order-reserve-full-plan-'.$serviceOrder->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $result = $productionPlanManager->reserveServiceOrder($serviceOrder, $this->authenticatedUser());
            $this->addFlash('success', sprintf('Agenda completa: %d operación(es) reservada(s) en %d tramo(s).', $result['operations'], $result['segments']));
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
    }

    #[Route('/{id}/partidas/{item}/prioridad/vista-previa', name: 'preview_item_priority', requirements: ['id' => '\\d+', 'item' => '\\d+'], methods: ['POST'])]
    public function previewItemPriority(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderItem $item,
        ProductionPriorityRescheduler $rescheduler,
    ): Response {
        $this->denyAccessUnlessGranted('production.priority.manage');
        $this->assertItemRouteOrder($serviceOrder, $item);
        if (!$this->isCsrfTokenValid('production-item-priority-'.$serviceOrder->getId().'-'.$item->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        $priority = ProductionPriority::tryFrom($request->request->getString('priority'));
        if ($priority === null) {
            $this->addFlash('error', 'Selecciona una prioridad válida.');
            return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
        }

        try {
            $reason = $request->request->getString('reason');
            $preview = $rescheduler->previewPriorityChange($item, $priority, $reason);
            $nonce = bin2hex(random_bytes(32));
            $request->getSession()->set($this->priorityPreviewSessionKey($serviceOrder, $item), [
                'nonce' => $nonce,
                'priority' => $priority->value,
                'reason' => trim($reason),
                'fingerprint' => $preview['fingerprint'],
                'expires_at' => time() + 900,
            ]);

            return $this->render('admin/service_orders/priority_preview.html.twig', [
                'serviceOrder' => $serviceOrder,
                'item' => $item,
                'priority' => $priority,
                'reason' => trim($reason),
                'previewNonce' => $nonce,
                'expiresAt' => time() + 900,
                'changes' => $preview['changes'],
                'risks' => $preview['risks'],
            ]);
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
    }

    #[Route('/{id}/partidas/{item}/prioridad/confirmar', name: 'confirm_item_priority', requirements: ['id' => '\\d+', 'item' => '\\d+'], methods: ['POST'])]
    public function confirmItemPriority(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderItem $item,
        ProductionPriorityRescheduler $rescheduler,
    ): Response {
        $this->denyAccessUnlessGranted('production.priority.manage');
        $this->assertItemRouteOrder($serviceOrder, $item);

        $key = $this->priorityPreviewSessionKey($serviceOrder, $item);
        $preview = $request->getSession()->get($key);
        $nonce = $request->request->getString('preview_nonce');
        if (!is_array($preview)
            || !isset($preview['nonce'], $preview['priority'], $preview['reason'], $preview['fingerprint'], $preview['expires_at'])
            || !is_string($nonce)
            || !hash_equals((string) $preview['nonce'], $nonce)
            || (int) $preview['expires_at'] < time()
            || !$this->isCsrfTokenValid('production-priority-confirm-'.$nonce, $request->request->getString('_token'))
        ) {
            $request->getSession()->remove($key);
            $this->addFlash('warning', 'La vista previa venció o ya fue utilizada. Calcula de nuevo el impacto.');
            return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
        }

        try {
            $priority = ProductionPriority::tryFrom((string) $preview['priority']);
            if ($priority === null) {
                throw new \DomainException('La prioridad de la vista previa ya no es válida.');
            }
            $result = $rescheduler->confirmPriorityChange(
                $item,
                $priority,
                (string) $preview['reason'],
                (string) $preview['fingerprint'],
                $this->authenticatedUser(),
            );
            $request->getSession()->remove($key);
            $this->addFlash('success', sprintf('Prioridad actualizada. Se reprogramaron %d operaciones.', $result['changed_plans']));
        } catch (\DomainException $exception) {
            $request->getSession()->remove($key);
            $this->addFlash('warning', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
    }

    #[Route('/{id}/operaciones/{plan}/fijar-agenda', name: 'set_operation_schedule_pin', requirements: ['id' => '\\d+', 'plan' => '\\d+'], methods: ['POST'])]
    public function setOperationSchedulePin(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderOperationPlan $plan,
        ProductionPriorityRescheduler $rescheduler,
    ): Response {
        $this->denyAccessUnlessGranted('production.schedule.reprogram');
        $this->assertPlanRouteOrder($serviceOrder, $plan);
        if (!$this->isCsrfTokenValid('production-schedule-pin-'.$plan->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        try {
            $pinned = $request->request->getString('pinned') === '1';
            $rescheduler->setSchedulePinned($plan, $pinned, $this->authenticatedUser());
            $this->addFlash('success', $pinned ? 'La reserva quedó fija ante reprogramaciones.' : 'La reserva volverá a participar en futuras reprogramaciones.');
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

    #[Route('/{id}/operaciones/{plan}/estimacion', name: 'estimate_item_operation', requirements: ['id' => '\\d+', 'plan' => '\\d+'], methods: ['POST'])]
    public function estimateItemOperation(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderOperationPlan $plan,
        ProductionPlanManager $productionPlanManager,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.plan');
        $this->assertPlanRouteOrder($serviceOrder, $plan);
        if (!$this->isCsrfTokenValid('service-order-operation-estimate-'.$plan->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }
        $unitValue = $request->request->getString('load_unit');
        $unit = $unitValue === '' ? null : ProductionLoadUnit::tryFrom($unitValue);
        if ($unitValue !== '' && $unit === null) {
            $this->addFlash('error', 'La unidad de carga no es válida.');
            return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
        }
        try {
            $productionPlanManager->estimate(
                $plan,
                $request->request->getString('load'),
                $unit,
                $request->request->getString('manual_minutes'),
                $request->request->getString('reason'),
                $this->authenticatedUser(),
            );
            $this->addFlash('success', 'La estimación de la operación quedó guardada.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
    }

    #[Route('/{id}/operaciones/{plan}/reservar', name: 'reserve_item_operation', requirements: ['id' => '\\d+', 'plan' => '\\d+'], methods: ['POST'])]
    public function reserveItemOperation(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderOperationPlan $plan,
        ProductionPlanManager $productionPlanManager,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.plan');
        $this->assertPlanRouteOrder($serviceOrder, $plan);
        if (!$this->isCsrfTokenValid('service-order-operation-reserve-'.$plan->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }
        try {
            $segments = $productionPlanManager->reserve($plan, $this->authenticatedUser());
            $this->addFlash('success', sprintf('Máquina reservada en %d tramo(s) de jornada.', count($segments)));
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
    }

    #[Route('/{id}/operaciones/{plan}/operador', name: 'assign_item_operation_operator', requirements: ['id' => '\\d+', 'plan' => '\\d+'], methods: ['POST'])]
    public function assignItemOperationOperator(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderOperationPlan $plan,
        UserRepository $userRepository,
        ProductionPlanManager $productionPlanManager,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.plan');
        $this->assertPlanRouteOrder($serviceOrder, $plan);
        if (!$this->isCsrfTokenValid('service-order-operation-operator-'.$plan->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }
        $operatorId = $request->request->getString('operator_id');
        try {
            $operator = null;
            if ($operatorId !== '') {
                if (preg_match('/^[1-9]\d*$/D', $operatorId) !== 1) {
                    throw new \DomainException('El operador seleccionado no es válido.');
                }
                $operator = $userRepository->find((int) $operatorId);
                if (!$operator instanceof User) {
                    throw new \DomainException('El operador seleccionado no existe.');
                }
            }
            $productionPlanManager->assignOperator($plan, $operator, $this->authenticatedUser());
            $this->addFlash('success', 'La asignación de operador quedó guardada.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
    }

    #[Route('/{id}/operaciones/{plan}/tiempo-real', name: 'record_item_operation_actual_time', requirements: ['id' => '\\d+', 'plan' => '\\d+'], methods: ['POST'])]
    public function recordItemOperationActualTime(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderOperationPlan $plan,
        ProductionPlanManager $productionPlanManager,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.plan');
        $this->assertPlanRouteOrder($serviceOrder, $plan);
        if (!$this->isCsrfTokenValid('service-order-operation-actual-'.$plan->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }
        $minutes = filter_var($request->request->getString('actual_minutes'), FILTER_VALIDATE_INT);
        try {
            if ($minutes === false) { throw new \DomainException('Captura los minutos reales como entero.'); }
            $productionPlanManager->recordActualMinutes($plan, $minutes, $request->request->getString('actual_note'), $this->authenticatedUser());
            $this->addFlash('success', 'El tiempo real quedó registrado.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
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

    #[Route('/{id}/partidas/{item}/dividir', name: 'split_item', requirements: ['id' => '\\d+', 'item' => '\\d+'], methods: ['POST'])]
    public function splitItem(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderItem $item,
        ServiceOrderManager $serviceOrderManager,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.plan');
        if (!$this->isCsrfTokenValid(
            'service-order-item-split-'.$serviceOrder->getId().'-'.$item->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('El token de seguridad para dividir la partida no es válido.');
        }

        try {
            $rows = $serviceOrderManager->splitItem(
                $serviceOrder,
                $item,
                $request->request->getString('quantities'),
                $request->request->getString('reason'),
                $this->authenticatedUser(),
            );
            $this->addFlash('success', sprintf('La partida se dividió en %d renglones. El total contratado de la orden se conservó.', count($rows)));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
    }

    #[Route('/{id}/partidas/{item}/corregir-medidas', name: 'correct_item_specifications', requirements: ['id' => '\\d+', 'item' => '\\d+'], methods: ['POST'])]
    public function correctItemSpecifications(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderItem $item,
        ServiceOrderManager $serviceOrderManager,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.plan');
        if (!$this->isCsrfTokenValid(
            'service-order-item-specifications-'.$serviceOrder->getId().'-'.$item->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('El token de seguridad para corregir las medidas no es válido.');
        }

        try {
            $serviceOrderManager->correctProductionSpecifications(
                $serviceOrder,
                $item,
                $request->request->getString('finished_width_cm'),
                $request->request->getString('finished_height_cm'),
                $request->request->getString('reason'),
                $this->authenticatedUser(),
            );
            $this->addFlash('success', 'Las medidas de producción se corrigieron. Las medidas cotizadas y los importes quedaron intactos; revisa la ruta antes de planificar.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_service_orders_show', ['id' => $serviceOrder->getId()]);
    }

    #[Route('/{id}/partidas/{item}/confirmar-revision-ruta', name: 'confirm_item_route_review', requirements: ['id' => '\\d+', 'item' => '\\d+'], methods: ['POST'])]
    public function confirmItemRouteReview(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceOrderItem $item,
        ServiceOrderManager $serviceOrderManager,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.plan');
        if (!$this->isCsrfTokenValid(
            'service-order-item-route-review-'.$serviceOrder->getId().'-'.$item->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('El token de seguridad para confirmar la ruta no es válido.');
        }

        try {
            $serviceOrderManager->confirmProductionRouteReview($serviceOrder, $item, $this->authenticatedUser());
            $this->addFlash('success', 'La ruta de producción quedó revisada para esta partida.');
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
        ProductionReservationRepository $reservationRepository,
        UserRepository $userRepository,
        ProductionCommitmentForecaster $commitmentForecaster,
        ProductionLoadResolver $loadResolver,
    ): Response {
        $this->denyAccessUnlessGranted('service_orders.view');

        $plansByItem = [];
        $allPlans = $operationPlanRepository->findActiveForServiceOrder($serviceOrder);
        foreach ($allPlans as $plan) {
            $plansByItem[(int) $plan->getServiceOrderItem()->getId()][] = $plan;
        }
        $reservationsByPlan = [];
        foreach ($reservationRepository->findActiveForServiceOrder($serviceOrder) as $reservation) {
            $reservationsByPlan[(int) $reservation->getPlan()->getId()][] = $reservation;
        }

        $canPlan = $serviceOrder->isPendingPlanning() && $this->isGranted('service_orders.plan');
        $canManageCommitment = $this->isGranted('service_orders.commitment.manage');
        $canManagePriority = $this->isGranted('production.priority.manage');
        $canReprogramSchedule = $this->isGranted('production.schedule.reprogram');
        $scheduleAdjustablePlans = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City'));
        foreach ($plansByItem as $plans) {
            foreach ($plans as $plan) {
                $reservations = $reservationsByPlan[(int) $plan->getId()] ?? [];
                $canAdjust = $reservations !== [];
                foreach ($reservations as $reservation) {
                    if ($reservation->getStartsAt() <= $now) {
                        $canAdjust = false;
                        break;
                    }
                }
                $scheduleAdjustablePlans[(int) $plan->getId()] = $canAdjust;
            }
        }
        $itemsForDisplay = $serviceOrder->getItems()->toArray();
        usort($itemsForDisplay, static function (ServiceOrderItem $left, ServiceOrderItem $right): int {
            $leftRoot = $left->getSplitParentItem() ?? $left;
            $rightRoot = $right->getSplitParentItem() ?? $right;
            $byRoot = $leftRoot->getLineNumber() <=> $rightRoot->getLineNumber();

            return $byRoot !== 0 ? $byRoot : ($left->getSplitPosition() ?? 0) <=> ($right->getSplitPosition() ?? 0);
        });
        $designItems = [];
        foreach ($allPlans as $plan) {
            if ($plan->getOperation()->getCode() === 'PRE-DISENO') {
                $designItems[(int) $plan->getServiceOrderItem()->getId()] = $plan->getServiceOrderItem();
            }
        }
        $designRequired = $designItems !== [];
        $designPendingItems = count(array_filter(
            $designItems,
            static fn (ServiceOrderItem $item): bool => $item->getDesignStatus()->value !== 'READY_FOR_PRINT',
        ));
        $designComplete = !$designRequired || $designPendingItems === 0;
        $productionStatus = $serviceOrder->getProductionStatus()->value;
        $deliveryStatus = $serviceOrder->getDeliveryStatus()->value;
        $planningComplete = $serviceOrder->isPlanned();
        $productionComplete = in_array($productionStatus, ['WAITING_QUALITY', 'PRODUCTION_COMPLETED'], true);
        $qualityComplete = $productionStatus === 'PRODUCTION_COMPLETED';
        $deliveryComplete = $deliveryStatus === 'DELIVERED';
        $completedOrderStages = (int) $planningComplete
            + (int) ($designRequired && $designComplete)
            + (int) $productionComplete
            + (int) $qualityComplete
            + (int) $deliveryComplete;
        $trackedOrderStages = $designRequired ? 5 : 4;
        $currentOrderStage = match (true) {
            $productionStatus === 'CANCELLED' => 'cancelled',
            !$planningComplete => 'planning',
            !$designComplete => 'design',
            in_array($productionStatus, ['WAITING_DESIGN', 'IN_PRODUCTION'], true) => 'production',
            in_array($productionStatus, ['WAITING_QUALITY', 'QUALITY_REWORK'], true) => 'quality',
            $productionStatus === 'PRODUCTION_COMPLETED' && !$deliveryComplete => 'delivery',
            default => 'complete',
        };
        $orderProgress = [
            'percent' => (int) round($completedOrderStages / $trackedOrderStages * 100),
            'current_stage' => $currentOrderStage,
            'design_required' => $designRequired,
            'design_pending_items' => $designPendingItems,
            'stages' => [
                'planning' => $planningComplete,
                'design' => $designComplete,
                'production' => $productionComplete,
                'quality' => $qualityComplete,
                'delivery' => $deliveryComplete,
            ],
        ];
        $planningForm = null;
        $itemOperationForms = [];
        $equipmentForms = [];
        $equipmentChoicesByPlan = [];
        $recommendedEquipmentByPlan = [];
        $automaticEstimateByPlan = [];
        $physicalEstimateByPlan = [];
        $requiredLoadUnitByPlan = [];
        $splitEligibleItems = [];
        $productionDimensions = [];
        $productionForecast = $commitmentForecaster->forOrder($serviceOrder);
        $planningProgress = [
            'operations' => count($allPlans),
            'with_equipment' => 0,
            'estimated' => 0,
            'reserved' => 0,
            'ready' => 0,
            'auto_actions' => 0,
        ];
        $planningHasIssues = false;
        foreach ($serviceOrder->getItems() as $item) {
            if ($item->getPlanningIssue() !== null) {
                $planningHasIssues = true;
            }
        }

        if ($canManageCommitment) {
            $suggestedDate = $productionForecast['order']['suggested_delivery'] ?? null;
            $planningForm = $this->createPlanningForm($this->planningData($serviceOrder, $suggestedDate), $serviceOrder)->createView();
        }
        if ($canPlan) {
            $operations = $operationRepository->findActiveForServiceOrderPlanning();
            $nowForCapacity = new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City'));

            foreach ($itemsForDisplay as $item) {
                $itemOperationForms[(int) $item->getId()] = $this->createItemOperationForm(
                    new ServiceOrderItemOperationData(),
                    $serviceOrder,
                    $item,
                    $operations,
                )->createView();

                foreach ($plansByItem[(int) $item->getId()] ?? [] as $plan) {
                    $planId = (int) $plan->getId();
                    $choices = $this->equipmentChoicesForPlan($plan, $equipmentRepository);
                    $equipmentChoicesByPlan[$planId] = $choices;
                    if ($plan->getEquipment() === null && count($choices) === 1) {
                        $recommendedEquipmentByPlan[$planId] = $choices[0];
                    }

                    $equipmentData = new ServiceOrderItemOperationEquipmentData();
                    $equipmentData->equipment = $plan->getEquipment();
                    $equipmentForms[(int) $plan->getId()] = $this->createEquipmentForm(
                        $equipmentData,
                        $serviceOrder,
                        $plan,
                        $choices,
                    )->createView();

                    $effectiveEquipment = $plan->getEquipment() ?? ($recommendedEquipmentByPlan[$planId] ?? null);
                    $capacityUnit = $effectiveEquipment?->getCapacityUnit();
                    $loadUnit = $capacityUnit?->loadUnit();
                    $automaticLoad = $loadUnit !== null ? $loadResolver->automaticLoad($item, $loadUnit) : null;
                    $capacityIsValid = $effectiveEquipment?->hasValidatedCapacityAt($nowForCapacity) ?? false;
                    $physicalEstimateByPlan[$planId] = $effectiveEquipment !== null && $loadUnit !== null && $capacityIsValid;
                    $automaticEstimateByPlan[$planId] = $plan->getEstimatedMinutes() !== null
                        || ($effectiveEquipment !== null
                            && $loadUnit !== null
                            && $automaticLoad !== null
                            && $capacityIsValid);
                    $requiredLoadUnitByPlan[$planId] = $loadUnit;

                    $hasEquipment = $plan->getEquipment() !== null;
                    $hasEstimate = $plan->getEstimatedMinutes() !== null && $plan->getEstimateKind() !== null;
                    $hasReservation = ($reservationsByPlan[$planId] ?? []) !== [];
                    if ($hasEquipment) { ++$planningProgress['with_equipment']; }
                    if ($hasEstimate) { ++$planningProgress['estimated']; }
                    if ($hasReservation) { ++$planningProgress['reserved']; }
                    if ($hasEquipment && $hasEstimate && $hasReservation && $plan->getBlockedReason() === null && $item->getPlanningIssue() === null) {
                        ++$planningProgress['ready'];
                    }
                    if ($plan->getBlockedReason() !== null || $item->getPlanningIssue() !== null) {
                        $planningHasIssues = true;
                    }
                    if ((!$hasEquipment && count($choices) === 1)
                        || (!$hasEstimate && $effectiveEquipment !== null && $automaticEstimateByPlan[$planId])) {
                        ++$planningProgress['auto_actions'];
                    }
                }

                $productionDimensions[(int) $item->getId()] = [
                    'finished_width_cm' => $this->snapshotDimension($item->getProductionSpecificationsSnapshot(), 'finished_width_cm'),
                    'finished_height_cm' => $this->snapshotDimension($item->getProductionSpecificationsSnapshot(), 'finished_height_cm'),
                ];

                $hasChildren = false;
                foreach ($itemsForDisplay as $candidate) {
                    if ($candidate->getSplitParentItem() === $item) {
                        $hasChildren = true;
                        break;
                    }
                }
                if ($item->getSplitParentItem() === null && $item->getSplitPosition() === null && !$hasChildren) {
                    $splitEligibleItems[(int) $item->getId()] = true;
                }
            }
        }

        $allPlansReadyToReserve = $planningProgress['operations'] > 0
            && $planningProgress['with_equipment'] === $planningProgress['operations']
            && $planningProgress['estimated'] === $planningProgress['operations']
            && !$planningHasIssues;
        $allPlansReserved = $planningProgress['operations'] > 0
            && $planningProgress['reserved'] === $planningProgress['operations'];
        $canReserveFullPlan = $canPlan && $allPlansReadyToReserve && !$allPlansReserved;
        $canMarkPlanned = $canPlan
            && $allPlansReadyToReserve
            && $allPlansReserved
            && $serviceOrder->getCommitmentDate() !== null;

        return $this->render('admin/service_orders/show.html.twig', [
            'serviceOrder' => $serviceOrder,
            'productionForecast' => $productionForecast,
            'orderProgress' => $orderProgress,
            'planningProgress' => $planningProgress,
            'planningHasIssues' => $planningHasIssues,
            'canReserveFullPlan' => $canReserveFullPlan,
            'canMarkPlanned' => $canMarkPlanned,
            'canPlan' => $canPlan,
            'canManageCommitment' => $canManageCommitment,
            'canManagePriority' => $canManagePriority,
            'canReprogramSchedule' => $canReprogramSchedule,
            'scheduleAdjustablePlans' => $scheduleAdjustablePlans,
            'productionPriorities' => ProductionPriority::cases(),
            'itemsForDisplay' => $itemsForDisplay,
            'planningForm' => $planningForm,
            'itemOperationForms' => $itemOperationForms,
            'equipmentForms' => $equipmentForms,
            'equipmentChoicesByPlan' => $equipmentChoicesByPlan,
            'recommendedEquipmentByPlan' => $recommendedEquipmentByPlan,
            'automaticEstimateByPlan' => $automaticEstimateByPlan,
            'physicalEstimateByPlan' => $physicalEstimateByPlan,
            'requiredLoadUnitByPlan' => $requiredLoadUnitByPlan,
            'operationPlansByItem' => $plansByItem,
            'reservationsByPlan' => $reservationsByPlan,
            'productionOperators' => $canPlan ? $userRepository->findActiveProductionOperators() : [],
            'productionLoadUnits' => ProductionLoadUnit::cases(),
            'canManageProductionPlanning' => $this->isGranted('service_orders.plan'),
            'splitEligibleItems' => $splitEligibleItems,
            'productionDimensions' => $productionDimensions,
        ]);
    }

    /** @param array<string, mixed> $snapshot */
    private function snapshotDimension(array $snapshot, string $key): ?string
    {
        $candidate = $snapshot['large_format']['values'][$key]
            ?? $snapshot['values'][strtoupper($key)]['submitted_value']
            ?? $snapshot[$key]
            ?? null;

        return is_scalar($candidate) && trim((string) $candidate) !== '' ? (string) $candidate : null;
    }

    private function planningData(ServiceOrder $serviceOrder, ?\DateTimeImmutable $suggestedDate = null): ServiceOrderPlanningData
    {
        $data = new ServiceOrderPlanningData();
        $data->commitmentDate = ($serviceOrder->getCommitmentDate() ?? $suggestedDate)?->format('Y-m-d');
        $data->reason = $serviceOrder->getCommitmentReason() ?? '';

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

    private function assertPlanRouteOrder(ServiceOrder $order, ServiceOrderOperationPlan $plan): void
    {
        if ($plan->getServiceOrderItem()->getServiceOrder()->getId() !== $order->getId()) {
            throw $this->createNotFoundException();
        }
    }

    private function assertItemRouteOrder(ServiceOrder $order, ServiceOrderItem $item): void
    {
        if ($item->getServiceOrder()->getId() !== $order->getId()) {
            throw $this->createNotFoundException();
        }
    }

    private function priorityPreviewSessionKey(ServiceOrder $order, ServiceOrderItem $item): string
    {
        return sprintf('production_priority_preview_%d_%d', $order->getId(), $item->getId());
    }
}
