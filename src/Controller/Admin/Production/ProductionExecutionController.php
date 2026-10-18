<?php

declare(strict_types=1);

namespace App\Controller\Admin\Production;

use App\Application\Production\ProductionExecutionCommand;
use App\Application\Production\ProductionExecutionManager;
use App\Application\Production\ProductionIncidentManager;
use App\Application\Production\ProductionMaterialUsageManager;
use App\Application\Production\ProductionOperatorAccess;
use App\Application\Production\PrintingManager;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Production\ProductionOperationExecution;
use App\Entity\Users\User;
use App\Enum\Orders\ProductionOrderStatus;
use App\Repository\Orders\ServiceOrderOperationPlanRepository;
use App\Repository\Orders\ServiceOrderTraceabilityEventRepository;
use App\Repository\Production\ProductionOperationExecutionRepository;
use App\Repository\Production\ProductionMaterialUsageRepository;
use App\Repository\Materials\MaterialVariantRepository;
use App\Repository\Inventory\InventoryCommitmentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/produccion/ejecucion', name: 'admin_production_execution_')]
final class ProductionExecutionController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        ServiceOrderOperationPlanRepository $planRepository,
        PrintingManager $printingManager,
        ServiceOrderTraceabilityEventRepository $eventRepository,
        ProductionOperationExecutionRepository $executionRepository,
        ProductionMaterialUsageRepository $materialUsageRepository,
        MaterialVariantRepository $materialVariantRepository,
    ): Response {
        $this->denyAccessUnlessGranted('production.view');
        $actor = $this->authenticatedUser();
        $isQueueManager = $this->isGranted('production.assign') || $this->isGranted('ROLE_ADMIN');
        $queuePlans = $planRepository->findForProductionQueue($isQueueManager ? null : $actor);
        $plans = [];
        $reworkOrigins = [];
        $specializedKinds = [];
        $startBlocks = [];
        $commandIds = [];
        $materialUsages = [];
        $taskStates = [];
        $canActOnPlans = [];
        $routePlansByPlanId = [];
        $equipmentOptions = [];
        $stats = ['in_progress' => 0, 'ready' => 0, 'paused_blocked' => 0, 'completed_today' => 0];
        $today = new \DateTimeImmutable('today', new \DateTimeZone('America/Mexico_City'));

        foreach ($queuePlans as $plan) {
            $order = $plan->getServiceOrderItem()->getServiceOrder();
            if (!$plan->isActive()
                || !in_array($order->getProductionStatus(), [
                    ProductionOrderStatus::WAITING_DESIGN,
                    ProductionOrderStatus::IN_PRODUCTION,
                    ProductionOrderStatus::QUALITY_REWORK,
                ], true)
            ) {
                continue;
            }

            $planId = (int) $plan->getId();
            $operationCode = $plan->getOperationSnapshot()['code'] ?? $plan->getOperation()->getCode();
            if ($plan->getBlockedReason() !== null) {
                $startBlocks[$planId] = 'Resuelve el bloqueo operativo antes de iniciar.';
            } elseif ($operationCode !== 'PRE-DISENO' && $plan->getServiceOrderItem()->getDesignStatus()->value !== 'READY_FOR_PRINT') {
                $startBlocks[$planId] = 'Esperando liberación de Diseño.';
            } else {
                foreach ($plan->getServiceOrderItem()->getActiveOperationPlans() as $previousPlan) {
                    if ($previousPlan->getSequenceNumber() < $plan->getSequenceNumber()
                        && (!$previousPlan->hasCompletedLatestExecution() || $previousPlan->hasOpenExecution())
                    ) {
                        $startBlocks[$planId] = 'Espera a que termine la operación anterior de esta partida.';
                        break;
                    }
                }
            }
            if ($printingManager->isPrintingPlan($plan)) {
                $specializedKinds[$planId] = 'printing';
            } elseif ($operationCode === 'PRE-DISENO') {
                $specializedKinds[$planId] = 'design';
            }
            $commandIds['plan'][$planId] = [
                'start' => bin2hex(random_bytes(24)),
                'open_issue' => bin2hex(random_bytes(24)),
                'resolve_issue' => bin2hex(random_bytes(24)),
                'plan_material' => bin2hex(random_bytes(24)),
            ];
            $latest = $plan->getLatestExecution();
            if ($latest instanceof ProductionOperationExecution && $latest->isOpen()) {
                $commandIds['execution'][(int) $latest->getId()] = [
                    'pause' => bin2hex(random_bytes(24)),
                    'resume' => bin2hex(random_bytes(24)),
                    'complete' => bin2hex(random_bytes(24)),
                    'actual_material' => bin2hex(random_bytes(24)),
                ];
            }

            if ($order->getProductionStatus() === ProductionOrderStatus::QUALITY_REWORK) {
                $event = $eventRepository->findLatestQualityRejectionForPlan($plan);
                $origin = $event?->getOperationExecution();
                if ($origin instanceof ProductionOperationExecution
                    && $plan->getLatestExecution() === $origin
                    && !$plan->hasExecutionOrigin($origin)
                ) {
                    $reworkOrigins[(int) $plan->getId()] = $origin;
                }
            }

            $latestStatus = $latest?->getStatus()->value;
            $state = match ($latestStatus) {
                'IN_PROGRESS' => 'in_progress',
                'PAUSED' => 'paused',
                default => null,
            };

            if ($state === null && $plan->getBlockedReason() !== null) {
                $state = 'blocked';
            } elseif ($state === null && isset($startBlocks[$planId])) {
                $state = 'blocked';
            } elseif ($state === null && $latestStatus === 'COMPLETED' && isset($reworkOrigins[$planId])) {
                $state = 'ready';
            } elseif ($state === null && $latestStatus === 'COMPLETED') {
                continue;
            } elseif ($state === null && $order->getProductionStatus() === ProductionOrderStatus::QUALITY_REWORK) {
                // En retrabajo, Calidad identifica exactamente la operación devuelta.
                continue;
            } elseif ($state === null) {
                $state = 'ready';
            }

            $plans[] = $plan;
            $taskStates[$planId] = $state;
            $canActOnPlans[$planId] = ProductionOperatorAccess::canActFor($plan->getAssignedOperator(), $actor);
            $materialUsages[$planId] = $materialUsageRepository->findForPlan($plan);

            $routePlans = $plan->getServiceOrderItem()->getActiveOperationPlans();
            usort($routePlans, static fn (ServiceOrderOperationPlan $left, ServiceOrderOperationPlan $right): int =>
                [$left->getSequenceNumber(), $left->getId()] <=> [$right->getSequenceNumber(), $right->getId()]
            );
            $routePlansByPlanId[$planId] = $routePlans;

            if ($plan->getEquipment() !== null) {
                $equipmentOptions[(int) $plan->getEquipment()->getId()] = $plan->getEquipmentSnapshot()['name'] ?? $plan->getEquipment()->getName();
            }

            if ($state === 'in_progress') {
                ++$stats['in_progress'];
            } elseif ($state === 'ready') {
                ++$stats['ready'];
            } elseif (in_array($state, ['paused', 'blocked'], true)) {
                ++$stats['paused_blocked'];
            }
        }

        $tomorrow = $today->modify('+1 day');
        foreach ($executionRepository->findCompletedBetween($today, $tomorrow, $isQueueManager ? null : $actor) as $execution) {
            $plan = $execution->getPlan();
            $item = $plan->getServiceOrderItem();
            $order = $item->getServiceOrder();
            $planId = (int) $plan->getId();
            $operationCode = $plan->getOperationSnapshot()['code'] ?? $plan->getOperation()->getCode();

            if ($plan->getLatestExecution() !== $execution
                || isset($taskStates[$planId])
                || $printingManager->isPrintingPlan($plan)
                || $operationCode === 'PRE-DISENO'
            ) {
                continue;
            }
            if ($order->getProductionStatus() === ProductionOrderStatus::QUALITY_REWORK) {
                $event = $eventRepository->findLatestQualityRejectionForPlan($plan);
                if ($event?->getOperationExecution() === $execution && !$plan->hasExecutionOrigin($execution)) {
                    continue;
                }
            }

            $plans[] = $plan;
            $taskStates[$planId] = $plan->getBlockedReason() !== null ? 'blocked' : 'completed';
            $canActOnPlans[$planId] = ProductionOperatorAccess::canActFor($execution->getAssignedOperator(), $actor);
            $materialUsages[$planId] = $materialUsageRepository->findForPlan($plan);
            $commandIds['plan'][$planId] = [
                'open_issue' => bin2hex(random_bytes(24)),
                'resolve_issue' => bin2hex(random_bytes(24)),
                'plan_material' => bin2hex(random_bytes(24)),
            ];
            $routePlans = $item->getActiveOperationPlans();
            usort($routePlans, static fn (ServiceOrderOperationPlan $left, ServiceOrderOperationPlan $right): int =>
                [$left->getSequenceNumber(), $left->getId()] <=> [$right->getSequenceNumber(), $right->getId()]
            );
            $routePlansByPlanId[$planId] = $routePlans;
            if ($plan->getEquipment() !== null) {
                $equipmentOptions[(int) $plan->getEquipment()->getId()] = $plan->getEquipmentSnapshot()['name'] ?? $plan->getEquipment()->getName();
            }
            if ($taskStates[$planId] === 'blocked') {
                ++$stats['paused_blocked'];
            } else {
                ++$stats['completed_today'];
            }
        }

        asort($equipmentOptions, SORT_NATURAL | SORT_FLAG_CASE);

        usort($plans, static function (ServiceOrderOperationPlan $left, ServiceOrderOperationPlan $right) use ($taskStates): int {
            $leftActive = $taskStates[(int) $left->getId()] === 'in_progress' ? 0 : 1;
            $rightActive = $taskStates[(int) $right->getId()] === 'in_progress' ? 0 : 1;
            if ($leftActive !== $rightActive) {
                return $leftActive <=> $rightActive;
            }
            $priority = $right->getServiceOrderItem()->getPriority()->weight() <=> $left->getServiceOrderItem()->getPriority()->weight();
            if ($priority !== 0) {
                return $priority;
            }
            $leftCommitment = $left->getServiceOrderItem()->getServiceOrder()->getCommitmentDate()?->getTimestamp() ?? PHP_INT_MAX;
            $rightCommitment = $right->getServiceOrderItem()->getServiceOrder()->getCommitmentDate()?->getTimestamp() ?? PHP_INT_MAX;
            if ($leftCommitment !== $rightCommitment) {
                return $leftCommitment <=> $rightCommitment;
            }
            $created = $left->getServiceOrderItem()->getServiceOrder()->getCreatedAt() <=> $right->getServiceOrderItem()->getServiceOrder()->getCreatedAt();
            if ($created !== 0) {
                return $created;
            }
            return [$left->getServiceOrderItem()->getLineNumber(), $left->getSequenceNumber()]
                <=> [$right->getServiceOrderItem()->getLineNumber(), $right->getSequenceNumber()];
        });

        return $this->render('admin/production_execution/index.html.twig', [
            'plans' => $plans,
            'reworkOrigins' => $reworkOrigins,
            'specializedKinds' => $specializedKinds,
            'startBlocks' => $startBlocks,
            'commandIds' => $commandIds,
            'materialUsages' => $materialUsages,
            'taskStates' => $taskStates,
            'canActOnPlans' => $canActOnPlans,
            'routePlansByPlanId' => $routePlansByPlanId,
            'equipmentOptions' => $equipmentOptions,
            'stats' => $stats,
            'materialChoices' => $this->isGranted('production.assign') ? $materialVariantRepository->findActiveConsumable() : [],
            'canExecute' => $this->isGranted('production.execute'),
            'canManageIncidents' => $this->isGranted('production.assign'),
            'isQueueManager' => $isQueueManager,
            'actorId' => $actor->getId(),
            'actorName' => $actor->getFullName(),
        ]);
    }

    #[Route('/operaciones/{id}/iniciar', name: 'start', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function start(
        Request $request,
        ServiceOrderOperationPlan $plan,
        ProductionExecutionManager $manager,
        PrintingManager $printingManager,
        ProductionOperationExecutionRepository $executionRepository,
    ): Response {
        $this->denyAccessUnlessGranted('production.execute');
        $this->assertGeneralPlan($plan, $printingManager);
        $this->assertCsrf($request, 'production-execution-start-'.$plan->getId());
        try {
            $origin = null;
            $originId = trim($request->request->getString('origin_execution_id'));
            if ($originId !== '') {
                if (preg_match('/^[1-9]\\d*$/D', $originId) !== 1) {
                    throw new \DomainException('El intento de origen no es válido.');
                }
                $origin = $executionRepository->find((int) $originId);
                if (!$origin instanceof ProductionOperationExecution) {
                    throw new \DomainException('No se encontró el intento que devolvió Calidad.');
                }
            }
            $manager->start($plan, $this->authenticatedUser(), $this->commandFromRequest($request), $origin);
            $this->addFlash('success', 'La operación quedó iniciada.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_production_execution_index');
    }

    #[Route('/intentos/{id}/pausar', name: 'pause', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function pause(Request $request, ProductionOperationExecution $execution, ProductionExecutionManager $manager, PrintingManager $printingManager): Response
    {
        $this->denyAccessUnlessGranted('production.execute');
        $this->assertGeneralPlan($execution->getPlan(), $printingManager);
        $this->assertCsrf($request, 'production-execution-pause-'.$execution->getId());
        try {
            $manager->pause($execution, $this->authenticatedUser(), $this->commandFromRequest($request));
            $this->addFlash('success', 'La operación quedó pausada con su motivo.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_production_execution_index');
    }

    #[Route('/intentos/{id}/reanudar', name: 'resume', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function resume(Request $request, ProductionOperationExecution $execution, ProductionExecutionManager $manager, PrintingManager $printingManager): Response
    {
        $this->denyAccessUnlessGranted('production.execute');
        $this->assertGeneralPlan($execution->getPlan(), $printingManager);
        $this->assertCsrf($request, 'production-execution-resume-'.$execution->getId());
        try {
            $manager->resume($execution, $this->authenticatedUser(), $this->commandFromRequest($request));
            $this->addFlash('success', 'La operación quedó reanudada.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_production_execution_index');
    }

    #[Route('/intentos/{id}/completar', name: 'complete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function complete(Request $request, ProductionOperationExecution $execution, ProductionExecutionManager $manager, PrintingManager $printingManager): Response
    {
        $this->denyAccessUnlessGranted('production.execute');
        $this->assertGeneralPlan($execution->getPlan(), $printingManager);
        $this->assertCsrf($request, 'production-execution-complete-'.$execution->getId());
        try {
            $manager->complete($execution, $this->authenticatedUser(), $this->commandFromRequest($request));
            $this->addFlash('success', 'La operación quedó completada y registrada en la trazabilidad.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_production_execution_index');
    }

    #[Route('/operaciones/{id}/materiales/prevision', name: 'plan_material', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function planMaterial(
        Request $request,
        ServiceOrderOperationPlan $plan,
        ProductionMaterialUsageManager $manager,
        PrintingManager $printingManager,
        MaterialVariantRepository $materialVariantRepository,
        InventoryCommitmentRepository $inventoryCommitmentRepository,
    ): Response {
        $this->denyAccessUnlessGranted('production.assign');
        $this->assertGeneralPlan($plan, $printingManager);
        $this->assertCsrf($request, 'production-material-planned-'.$plan->getId());
        try {
            if ($inventoryCommitmentRepository->findForOrder($plan->getServiceOrderItem()->getServiceOrder()) !== []) {
                throw new \DomainException('La orden ya tiene materiales apartados. Libera o concilia sus apartados antes de cambiar la previsión.');
            }
            $variant = $materialVariantRepository->find($this->positiveId($request->request->getString('material_variant_id')));
            if (!$variant instanceof \App\Entity\Materials\MaterialVariant) {
                throw new \DomainException('Selecciona una variante de material válida.');
            }
            $manager->addPlanned(
                $plan,
                $this->authenticatedUser(),
                $this->commandFromRequest($request),
                $variant,
                $request->request->getString('quantity'),
            );
            $this->addFlash('success', 'El material previsto quedó registrado para esta operación.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_production_execution_index');
    }

    #[Route('/intentos/{id}/materiales/usados', name: 'actual_material', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function actualMaterial(
        Request $request,
        ProductionOperationExecution $execution,
        ProductionMaterialUsageManager $manager,
        PrintingManager $printingManager,
    ): Response {
        $this->denyAccessUnlessGranted('production.execute');
        $this->assertGeneralPlan($execution->getPlan(), $printingManager);
        $this->assertCsrf($request, 'production-material-used-'.$execution->getId());
        try {
            $manager->recordActual(
                $execution,
                $this->authenticatedUser(),
                $this->commandFromRequest($request),
                $request->request->getString('material_name'),
                $request->request->getString('quantity'),
                $request->request->getString('unit'),
                $request->request->getString('waste') ?: '0',
                $request->request->getString('waste_reason') ?: null,
            );
            $this->addFlash('success', 'El material usado quedó registrado sin afectar existencias.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_production_execution_index');
    }

    #[Route('/operaciones/{id}/incidencia', name: 'open_issue', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function openIssue(Request $request, ServiceOrderOperationPlan $plan, ProductionIncidentManager $manager, PrintingManager $printingManager): Response
    {
        $this->denyAccessUnlessGranted('production.assign');
        $this->assertGeneralPlan($plan, $printingManager);
        $this->assertCsrf($request, 'production-issue-open-'.$plan->getId());
        try {
            $manager->open($plan, $this->authenticatedUser(), $this->commandFromRequest($request));
            $this->addFlash('success', 'La incidencia quedó abierta; la operación no podrá reanudarse hasta resolverla.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_production_execution_index');
    }

    #[Route('/operaciones/{id}/incidencia/resolver', name: 'resolve_issue', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function resolveIssue(Request $request, ServiceOrderOperationPlan $plan, ProductionIncidentManager $manager, PrintingManager $printingManager): Response
    {
        $this->denyAccessUnlessGranted('production.assign');
        $this->assertGeneralPlan($plan, $printingManager);
        $this->assertCsrf($request, 'production-issue-resolve-'.$plan->getId());
        try {
            $manager->resolve($plan, $this->authenticatedUser(), $this->commandFromRequest($request));
            $this->addFlash('success', 'La incidencia quedó resuelta.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_production_execution_index');
    }

    private function assertGeneralPlan(ServiceOrderOperationPlan $plan, PrintingManager $printingManager): void
    {
        if ($printingManager->isPrintingPlan($plan)
            || ($plan->getOperationSnapshot()['code'] ?? $plan->getOperation()->getCode()) === 'PRE-DISENO'
        ) {
            throw $this->createNotFoundException();
        }
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }
    }

    private function authenticatedUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        return $user;
    }

    private function commandFromRequest(Request $request): ProductionExecutionCommand
    {
        $version = filter_var($request->request->getString('expected_order_version'), FILTER_VALIDATE_INT);
        if ($version === false) {
            throw new \InvalidArgumentException('La versión de la orden no es válida. Actualiza la pantalla.');
        }
        return new ProductionExecutionCommand(
            $request->request->getString('command_id'),
            $version,
            $request->request->getString('note') ?: null,
        );
    }

    private function positiveId(string $value): ?int
    {
        return preg_match('/^[1-9]\d*$/D', trim($value)) === 1 ? (int) $value : null;
    }
}
