<?php

declare(strict_types=1);

namespace App\Controller\Admin\Production;

use App\Application\Inventory\InventoryPrintingMaterialResolver;
use App\Application\Production\PrintingManager;
use App\Application\Production\ProductionExecutionCommand;
use App\Entity\Equipment\Equipment;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Production\ProductionOperationExecution;
use App\Entity\Production\ProductionPrintedOutput;
use App\Entity\Users\User;
use App\Repository\Equipment\EquipmentRepository;
use App\Repository\Inventory\InventoryStockUnitRepository;
use App\Repository\Materials\MaterialVariantRepository;
use App\Repository\Orders\ServiceOrderOperationPlanRepository;
use App\Repository\Production\ProductionPrintedOutputRepository;
use App\Repository\Production\ProductionMaterialUsageRepository;
use App\Repository\Production\ProductionPrintingScanRepository;
use App\Repository\Production\ServiceOrderItemDesignReleaseRepository;
use App\Service\Production\PrintingLabelRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/impresion', name: 'admin_printing_')]
final class PrintingController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        ServiceOrderOperationPlanRepository $planRepository,
        ProductionPrintedOutputRepository $outputRepository,
        PrintingManager $printingManager,
    ): Response {
        $this->denyAccessUnlessGranted('production.view');
        $plans = array_values(array_filter(
            $planRepository->findForProductionQueue(),
            static fn (ServiceOrderOperationPlan $plan): bool => $printingManager->isPrintingPlan($plan),
        ));
        $outputs = [];
        $summary = [
            'ready' => 0,
            'in_progress' => 0,
            'pending_configuration' => 0,
            'requiring_action' => 0,
            'at_risk' => 0,
            'completed' => 0,
        ];
        $machineNames = [];
        $timezone = new \DateTimeZone('America/Mexico_City');
        $today = new \DateTimeImmutable('today', $timezone);
        foreach ($plans as $plan) {
            $output = $outputRepository->findCurrent($plan->getServiceOrderItem());
            $outputs[(int) $plan->getId()] = $output;
            $isCompleted = $output?->isLabelPlaced() ?? false;
            $isOverdue = $plan->getServiceOrderItem()->getServiceOrder()->getCommitmentDate() !== null
                && $plan->getServiceOrderItem()->getServiceOrder()->getCommitmentDate()->setTimezone($timezone) < $today
                && !$isCompleted;
            $isBlocked = $plan->getServiceOrderItem()->getDesignStatus()->value === 'BLOCKED';
            if ($isCompleted) {
                ++$summary['completed'];
            } else {
                ++$summary['requiring_action'];
            }
            if ($isOverdue || $isBlocked) {
                ++$summary['at_risk'];
            }

            $isConfigured = $plan->getEquipment() !== null && $plan->getMaterialVariant() !== null;
            if ($plan->hasOpenExecution()) {
                ++$summary['in_progress'];
            } elseif (!$isConfigured && $output === null) {
                ++$summary['pending_configuration'];
            } elseif (
                $isConfigured
                && $output === null
                && !$plan->hasCompletedExecution()
                && $plan->getServiceOrderItem()->getDesignStatus()->value === 'READY_FOR_PRINT'
            ) {
                ++$summary['ready'];
            }

            if ($plan->getEquipment() !== null) {
                $machineNames[$plan->getEquipment()->getName()] = $plan->getEquipment()->getName();
            }
        }
        natcasesort($machineNames);

        return $this->render('admin/printing/index.html.twig', [
            'plans' => $plans,
            'outputs' => $outputs,
            'printingSummary' => $summary,
            'printingMachines' => array_values($machineNames),
            'printingToday' => $today,
        ]);
    }

    #[Route('/operaciones/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        ServiceOrderOperationPlan $plan,
        PrintingManager $printingManager,
        InventoryPrintingMaterialResolver $printingMaterialResolver,
        EquipmentRepository $equipmentRepository,
        MaterialVariantRepository $materialVariantRepository,
        ServiceOrderItemDesignReleaseRepository $releaseRepository,
        ProductionPrintingScanRepository $scanRepository,
        InventoryStockUnitRepository $stockUnitRepository,
        ProductionPrintedOutputRepository $outputRepository,
        ProductionMaterialUsageRepository $usageRepository,
        PrintingLabelRenderer $labelRenderer,
    ): Response {
        $this->denyAccessUnlessGranted('production.view');
        if (!$printingManager->isPrintingPlan($plan)) {
            throw $this->createNotFoundException();
        }
        $item = $plan->getServiceOrderItem();
        $reworkOrigin = $printingManager->reworkOriginForPlan($plan);
        $activeExecution = null;
        foreach ($plan->getExecutions() as $execution) {
            if ($execution->isOpen()) {
                $activeExecution = $execution;
                break;
            }
        }

        $output = $outputRepository->findCurrent($item);
        $currentScan = $scanRepository->findCurrentForPlan($plan);
        $scannedStockUnit = $currentScan?->getStockUnit();

        return $this->render('admin/printing/show.html.twig', [
            'plan' => $plan,
            'item' => $item,
            'release' => $releaseRepository->findCurrent($item),
            'currentScan' => $currentScan,
            'surplusStockUnits' => $scannedStockUnit !== null ? $stockUnitRepository->findSurplusesForParent($scannedStockUnit) : [],
            'activeExecution' => $activeExecution,
            'reworkOrigin' => $reworkOrigin,
            'canStartPrinting' => !$plan->hasCompletedExecution() || $reworkOrigin !== null,
            'output' => $output,
            'outputQrSvg' => $output !== null ? $labelRenderer->renderQrSvg($output) : null,
            'materialUsages' => $usageRepository->findForPlan($plan),
            'allowedMaterialVariants' => $printingMaterialResolver->allowedVariantsForPlan($plan),
            'equipmentChoices' => $equipmentRepository->findAvailableForFutureExecution($plan->getOperation()),
            'materialChoices' => $materialVariantRepository->findActiveConsumable(),
            'canConfigure' => $this->isGranted('production.assign'),
            'canSubstitute' => $this->isGranted('production.substitution.approve'),
            'canExecute' => $this->isGranted('production.execute'),
            'canPrintLabels' => $this->isGranted('production.labels.print'),
            'canPrintInventoryLabels' => $this->isGranted('inventory.labels.print'),
            'orderVersion' => $item->getServiceOrder()->getTraceabilityVersion(),
            'startCommandId' => bin2hex(random_bytes(24)),
            'completeCommandId' => bin2hex(random_bytes(24)),
        ]);
    }

    #[Route('/operaciones/{id}/requisitos', name: 'configure', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function configure(Request $request, ServiceOrderOperationPlan $plan, EquipmentRepository $equipmentRepository, MaterialVariantRepository $materialVariantRepository, PrintingManager $manager): Response
    {
        $this->denyAccessUnlessGranted('production.assign');
        $this->assertPrintingPlan($plan, $manager);
        $this->assertCsrf($request, 'printing-configure-'.$plan->getId());
        try {
            $equipment = $this->findOptionalEquipment($request->request->getString('equipment_id'), $equipmentRepository);
            $material = $materialVariantRepository->find($this->positiveId($request->request->getString('material_variant_id')));
            if (!$material instanceof \App\Entity\Materials\MaterialVariant) {
                throw new \DomainException('Selecciona un material válido.');
            }
            if (($plan->getEquipment() !== $equipment || $plan->getMaterialVariant() !== $material) && !$this->isGranted('production.substitution.approve') && $plan->getMaterialVariant() !== null) {
                throw new \DomainException('Solo el jefe de Producción o Administrador puede autorizar una sustitución.');
            }
            $manager->configureRequirements($plan, $equipment, $material, $request->request->getString('reason'), $this->authenticatedUser());
            $this->addFlash('success', 'Los requisitos técnicos de Impresión quedaron registrados. Escanea ambos códigos para validarlos.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_printing_show', ['id' => $plan->getId()]);
    }

    #[Route('/operaciones/{id}/validar-escaneos', name: 'validate_scans', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function validateScans(Request $request, ServiceOrderOperationPlan $plan, PrintingManager $manager): Response
    {
        $this->denyAccessUnlessGranted('production.execute');
        $this->assertPrintingPlan($plan, $manager);
        $this->assertCsrf($request, 'printing-scan-'.$plan->getId());
        try {
            if ($request->request->getString('capture_mode') === 'MANUAL' && !$this->isGranted('production.substitution.approve')) {
                throw new \DomainException('La captura manual solo puede ser autorizada por el jefe de Producción o Administrador.');
            }
            $materialQrPayload = $request->request->getString('material_qr_payload') ?: $request->request->getString('material_barcode');
            $result = $manager->validateScans($plan, $request->request->getString('equipment_code'), $materialQrPayload, $this->authenticatedUser());
            $this->addFlash($result->accepted ? 'success' : 'error', $result->message);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_printing_show', ['id' => $plan->getId()]);
    }

    #[Route('/operaciones/{id}/iniciar', name: 'start', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function start(Request $request, ServiceOrderOperationPlan $plan, PrintingManager $manager): Response
    {
        $this->denyAccessUnlessGranted('production.execute');
        $this->assertPrintingPlan($plan, $manager);
        $this->assertCsrf($request, 'printing-start-'.$plan->getId());
        try {
            $manager->start($plan, $this->authenticatedUser(), $this->commandFromRequest($request));
            $this->addFlash('success', 'Impresión iniciada. Al terminar registra el consumo real antes de cerrar.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_printing_show', ['id' => $plan->getId()]);
    }

    #[Route('/intentos/{id}/completar', name: 'complete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function complete(Request $request, ProductionOperationExecution $execution, PrintingManager $manager): Response
    {
        $this->denyAccessUnlessGranted('production.execute');
        $plan = $execution->getPlan();
        $this->assertPrintingPlan($plan, $manager);
        $this->assertCsrf($request, 'printing-complete-'.$execution->getId());
        try {
            $result = $manager->complete(
                $execution,
                $this->authenticatedUser(),
                $this->commandFromRequest($request, 'Impresión completada con consumo registrado.'),
                $request->request->getString('total_consumed'),
                $request->request->has('waste') ? $request->request->getString('waste') : null,
                $request->request->getString('measurement_method'),
                $request->request->getString('waste_reason'),
                $request->request->getString('surplus_quantity'),
                $request->request->getString('surplus_location'),
                $request->request->getString('surplus_description'),
            );
            if ($result->surplusStockUnit !== null) {
                $this->addFlash('success', 'Impresión terminada. El sobrante '.$result->surplusStockUnit->getUnitCode().' tiene una etiqueta QR lista para imprimir.');
                return $this->redirectToRoute('admin_printing_show', ['id' => $plan->getId()]);
            }
            if ($result->output instanceof ProductionPrintedOutput) {
                $this->addFlash('success', 'Impresión terminada. Genera e imprime la etiqueta QR de esta salida.');
                return $this->redirectToRoute('admin_printing_label', ['id' => $result->output->getId()]);
            }
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_printing_show', ['id' => $plan->getId()]);
    }

    #[Route('/salidas/{id}/etiqueta', name: 'label', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function label(ProductionPrintedOutput $output, PrintingLabelRenderer $renderer, PrintingManager $manager): Response
    {
        $this->denyAccessUnlessGranted('production.labels.print');
        try {
            $html = $renderer->render($output);
            $manager->recordLabelGenerated($output, $this->authenticatedUser());
            return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        } catch (\DomainException|\RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());
            return $this->redirectToRoute('admin_printing_show', ['id' => $output->getExecution()->getPlan()->getId()]);
        }
    }

    #[Route('/salidas/{id}/reimprimir-etiqueta', name: 'reprint_label', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reprintLabel(Request $request, ProductionPrintedOutput $output, PrintingLabelRenderer $renderer, PrintingManager $manager): Response
    {
        $this->denyAccessUnlessGranted('production.labels.print');
        $this->assertCsrf($request, 'printing-reprint-label-'.$output->getId());
        try {
            $reason = trim($request->request->getString('reason'));
            if (mb_strlen($reason) < 5) { throw new \DomainException('Indica el motivo de la reimpresión de etiqueta.'); }
            $html = $renderer->render($output);
            $manager->recordLabelGenerated($output, $this->authenticatedUser(), $reason);
            return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        } catch (\DomainException|\RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());
            return $this->redirectToRoute('admin_printing_show', ['id' => $output->getExecution()->getPlan()->getId()]);
        }
    }

    #[Route('/salidas/{id}/confirmar-etiqueta', name: 'confirm_label_placed', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function confirmLabelPlaced(Request $request, ProductionPrintedOutput $output, PrintingManager $manager): Response
    {
        $this->denyAccessUnlessGranted('production.execute');
        $this->assertCsrf($request, 'printing-label-placed-'.$output->getId());
        try {
            $manager->confirmLabelPlaced($output, $this->authenticatedUser());
            $this->addFlash('success', 'La colocación de la etiqueta quedó confirmada.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_printing_show', ['id' => $output->getExecution()->getPlan()->getId()]);
    }

    private function assertPrintingPlan(ServiceOrderOperationPlan $plan, PrintingManager $manager): void
    {
        if (!$manager->isPrintingPlan($plan)) { throw $this->createNotFoundException(); }
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
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        return $user;
    }

    private function positiveId(string $value): int
    {
        if (preg_match('/^[1-9]\d*$/D', trim($value)) !== 1) { throw new \DomainException('La selección recibida no es válida.'); }
        return (int) $value;
    }

    private function findOptionalEquipment(string $value, EquipmentRepository $repository): ?Equipment
    {
        if (trim($value) === '') { return null; }
        $equipment = $repository->find($this->positiveId($value));
        if (!$equipment instanceof Equipment) { throw new \DomainException('La máquina seleccionada no existe.'); }
        return $equipment;
    }

    private function commandFromRequest(Request $request, ?string $defaultNote = null): ProductionExecutionCommand
    {
        $version = filter_var($request->request->getString('expected_order_version'), FILTER_VALIDATE_INT);
        if ($version === false) { throw new \InvalidArgumentException('La versión de la orden no es válida.'); }
        return new ProductionExecutionCommand($request->request->getString('command_id'), $version, $request->request->getString('note') ?: $defaultNote);
    }
}
