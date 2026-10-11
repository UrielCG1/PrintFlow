<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Production\ProductionFile;
use App\Entity\Production\ProductionOperationExecution;
use App\Entity\Production\ServiceOrderItemDesignRelease;
use App\Entity\Users\User;
use App\Enum\Orders\ProductionOrderStatus;
use App\Enum\Production\DesignAuthorizationType;
use App\Enum\Production\ProductionFilePurpose;
use App\Enum\Production\ProductionFileSource;
use App\Repository\Orders\ServiceOrderOperationPlanRepository;
use App\Repository\Production\ProductionFileRepository;
use App\Repository\Production\ProductionOperationExecutionRepository;
use App\Repository\Production\ServiceOrderItemDesignReleaseRepository;
use App\Repository\Orders\ServiceOrderTraceabilityEventRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Orders\ServiceOrderTraceabilityRecorder;
use App\Service\Production\ProductionFileStorage;
use App\Service\Production\StoredProductionFile;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Coordina la revisión interna y la liberación trazable de una partida. */
final class DesignReviewManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceOrderOperationPlanRepository $planRepository,
        private readonly ProductionFileRepository $fileRepository,
        private readonly ServiceOrderItemDesignReleaseRepository $releaseRepository,
        private readonly ProductionOperationExecutionRepository $executionRepository,
        private readonly ServiceOrderTraceabilityEventRepository $eventRepository,
        private readonly ProductionFileStorage $fileStorage,
        private readonly ServiceOrderTraceabilityRecorder $traceabilityRecorder,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function startReview(ServiceOrderItem $item, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($item, $actor): void {
            $plan = $this->lockAndAssertDesignAssignment($item, $actor);
            $order = $item->getServiceOrder();
            if ($plan->hasOpenExecution()) {
                throw new \DomainException('La partida ya tiene un intento de Diseño en curso.');
            }
            if ($plan->getBlockedReason() !== null) {
                throw new \DomainException('Resuelve el bloqueo operativo antes de iniciar Diseño.');
            }
            $origin = null;
            if ($order->getProductionStatus() === ProductionOrderStatus::QUALITY_REWORK) {
                $event = $this->eventRepository->findLatestQualityRejectionForPlan($plan);
                $origin = $event?->getOperationExecution();
                if (!$origin instanceof ProductionOperationExecution
                    || $origin->getStatus() !== \App\Enum\Production\ProductionOperationExecutionStatus::COMPLETED
                    || $plan->getLatestExecution() !== $origin
                    || $plan->hasExecutionOrigin($origin)
                ) {
                    throw new \DomainException('No hay una devolución vigente de Calidad para iniciar este retrabajo de Diseño.');
                }
            } elseif ($plan->hasCompletedLatestExecution()) {
                throw new \DomainException('Una revisión de Diseño completada solo puede repetirse por autorización de Calidad.');
            }
            $previous = $this->itemState($item);
            $item->beginDesignReview();
            if ($order->getProductionStatus() === ProductionOrderStatus::WAITING_DESIGN) {
                $order->beginProduction();
            } elseif ($order->getProductionStatus() === ProductionOrderStatus::QUALITY_REWORK) {
                $order->resumeProductionAfterQualityRework();
            }
            $execution = new ProductionOperationExecution(
                $plan,
                $actor,
                $actor,
                $plan->nextExecutionAttemptNumber(),
                $origin,
            );
            $plan->addExecution($execution);
            $this->entityManager->persist($execution);
            $order->touchTraceability();
            $new = $this->itemState($item);

            $this->traceabilityRecorder->record($order, 'design.review_started', $actor, $previous, $new, $item, $plan, $execution);
            $this->auditLogger->record($actor, 'design.review_started', 'service_order_item', $item->getId(), $previous, $new);
            $this->entityManager->flush();
        });
    }

    public function authorizeClientOriginal(ServiceOrderItem $item, UploadedFile $reference, ?string $instructions, User $actor): void
    {
        $storedReference = $this->fileStorage->storeDesignReference($reference);

        $this->entityManager->wrapInTransaction(function () use ($item, $storedReference, $instructions, $actor): void {
            $plan = $this->lockAndAssertDesignAssignment($item, $actor);
            $this->assertArtworkCanChange($item, $plan);
            $this->assertReviewInProgress($item);
            $artwork = $this->findOrCreateClientOriginal($item);
            $this->createRelease($item, $plan, $artwork, $storedReference, DesignAuthorizationType::CLIENT_ORIGINAL, $instructions, $actor);
        });
    }

    public function authorizePreparedArtwork(ServiceOrderItem $item, UploadedFile $artwork, UploadedFile $reference, ?string $instructions, User $actor): void
    {
        $storedArtwork = $this->fileStorage->storePreparedArtwork($artwork);
        $storedReference = $this->fileStorage->storeDesignReference($reference);

        $this->entityManager->wrapInTransaction(function () use ($item, $storedArtwork, $storedReference, $instructions, $actor): void {
            $plan = $this->lockAndAssertDesignAssignment($item, $actor);
            $this->assertArtworkCanChange($item, $plan);
            $this->assertReviewInProgress($item);
            $artwork = $this->createFile($item, ProductionFilePurpose::PREPARED_ARTWORK, $storedArtwork, ProductionFileSource::DESIGN_UPLOAD, $actor);
            $this->createRelease($item, $plan, $artwork, $storedReference, DesignAuthorizationType::PREPARED_ARTWORK, $instructions, $actor);
        });
    }

    private function createRelease(
        ServiceOrderItem $item,
        ServiceOrderOperationPlan $plan,
        ProductionFile $artwork,
        StoredProductionFile $storedReference,
        DesignAuthorizationType $authorizationType,
        ?string $instructions,
        User $actor,
    ): void {
        $order = $item->getServiceOrder();
        $previous = $this->itemState($item);
        $execution = $this->executionRepository->findOpenForPlanForUpdate($plan);
        if (!$execution instanceof ProductionOperationExecution) {
            throw new \DomainException('La partida debe tener una revisión de Diseño en curso antes de liberar el arte.');
        }
        $current = $this->releaseRepository->findCurrentForUpdate($item);
        if ($current instanceof ServiceOrderItemDesignRelease) {
            $current->supersede();
        }

        $reference = $this->createFile($item, ProductionFilePurpose::DESIGN_REFERENCE, $storedReference, ProductionFileSource::DESIGN_UPLOAD, $actor);
        $release = new ServiceOrderItemDesignRelease(
            $item,
            $artwork,
            $reference,
            $authorizationType,
            $this->releaseRepository->nextVersion($item),
            $instructions,
            $actor,
        );
        $this->entityManager->persist($release);
        $item->markReadyForPrint();
        $execution->complete('Arte autorizado por Diseño.');
        if ($order->getProductionStatus() === ProductionOrderStatus::IN_PRODUCTION && $this->allOperationsCompleted($order)) {
            $order->waitForQualityReview();
        }
        $order->touchTraceability();
        $new = $this->itemState($item, $release);

        $this->traceabilityRecorder->record(
            $order,
            'design.artwork_authorized',
            $actor,
            $previous,
            $new,
            $item,
            $plan,
            $execution,
            null,
            [
                'authorization_type' => $authorizationType->value,
                'artwork_sha256' => $artwork->getSha256(),
                'reference_sha256' => $reference->getSha256(),
                'release_version' => $release->getVersionNumber(),
            ],
        );
        $this->auditLogger->record($actor, 'design.artwork_authorized', 'service_order_item', $item->getId(), $previous, $new);
        $this->entityManager->flush();
    }

    private function findOrCreateClientOriginal(ServiceOrderItem $item): ProductionFile
    {
        $existing = $this->fileRepository->findLatestForItemAndPurpose($item, ProductionFilePurpose::CLIENT_ORIGINAL);
        if ($existing instanceof ProductionFile) {
            return $existing;
        }

        $snapshot = $item->getSourceFileSnapshot() ?? [];
        $path = $snapshot['path'] ?? null;
        if (!is_string($path) || trim($path) === '') {
            throw new \DomainException('La partida no tiene archivo original del cliente; carga un arte preparado.');
        }
        $originalName = is_string($snapshot['original_name'] ?? null) ? $snapshot['original_name'] : 'original-del-cliente';

        if (str_starts_with(str_replace('\\', '/', $path), 'uploads/quotations/')) {
            $stored = $this->fileStorage->importLegacyPublicRequest($path, $originalName);
            $source = ProductionFileSource::LEGACY_MIGRATION;
        } else {
            $stored = $this->fileStorage->inspectPrivate($path, $originalName);
            $source = ProductionFileSource::PUBLIC_REQUEST;
        }

        return $this->createFile($item, ProductionFilePurpose::CLIENT_ORIGINAL, $stored, $source, null);
    }

    private function createFile(
        ServiceOrderItem $item,
        ProductionFilePurpose $purpose,
        StoredProductionFile $stored,
        ProductionFileSource $source,
        ?User $actor,
    ): ProductionFile {
        $file = new ProductionFile(
            $item,
            $purpose,
            $this->fileRepository->nextVersion($item, $purpose),
            $stored->internalPath,
            $stored->originalName,
            $stored->mimeType,
            $stored->byteSize,
            $stored->sha256,
            $source,
            $actor,
        );
        $this->entityManager->persist($file);

        return $file;
    }

    private function lockAndAssertDesignAssignment(ServiceOrderItem $item, User $actor): ServiceOrderOperationPlan
    {
        $order = $item->getServiceOrder();
        $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->lock($item, LockMode::PESSIMISTIC_WRITE);
        if (!$order->isPlanned()) {
            throw new \DomainException('La orden debe estar planificada antes de revisarse en Diseño.');
        }

        $plans = $this->planRepository->findActiveForItemForUpdate($item);
        $plan = $plans[0] ?? null;
        if (!$plan instanceof ServiceOrderOperationPlan || $this->operationCode($plan) !== 'PRE-DISENO') {
            throw new \DomainException('La partida no tiene a Diseño como primera etapa activa de su ruta.');
        }
        $assignedOperator = $plan->getAssignedOperator();
        if ($assignedOperator === null || !$this->sameUser($assignedOperator, $actor)) {
            throw new \DomainException('Solo la persona asignada a Diseño puede revisar esta partida.');
        }

        return $plan;
    }

    private function assertReviewInProgress(ServiceOrderItem $item): void
    {
        if ($item->getDesignStatus()->value !== 'IN_REVIEW') {
            throw new \DomainException('Inicia la revisión de Diseño antes de liberar el arte.');
        }
    }

    private function assertArtworkCanChange(ServiceOrderItem $item, ServiceOrderOperationPlan $plan): void
    {
        if ($this->executionRepository->hasStartedAfterDesignForItem($item) && !$this->isAuthorizedRework($plan)) {
            throw new \DomainException('No se puede sustituir el arte después de iniciar una etapa posterior. Registra un retrabajo autorizado.');
        }
    }

    private function isAuthorizedRework(ServiceOrderOperationPlan $plan): bool
    {
        $execution = $plan->getLatestExecution();
        $origin = $execution?->getOriginExecution();

        return $execution?->isOpen() === true
            && $origin instanceof ProductionOperationExecution
            && $this->eventRepository->hasQualityRejectionForExecution($origin);
    }

    private function allOperationsCompleted(ServiceOrder $order): bool
    {
        $hasOperations = false;
        foreach ($order->getItems() as $item) {
            foreach ($item->getActiveOperationPlans() as $plan) {
                $hasOperations = true;
                if ($plan->hasOpenExecution() || !$plan->hasCompletedLatestExecution() || $plan->getBlockedReason() !== null) {
                    return false;
                }
            }
        }

        return $hasOperations;
    }

    private function operationCode(ServiceOrderOperationPlan $plan): string
    {
        $code = $plan->getOperationSnapshot()['code'] ?? null;

        return is_string($code) ? strtoupper($code) : $plan->getOperation()->getCode();
    }

    /** @return array<string, int|string|null> */
    private function itemState(ServiceOrderItem $item, ?ServiceOrderItemDesignRelease $release = null): array
    {
        return [
            'design_status' => $item->getDesignStatus()->value,
            'production_status' => $item->getServiceOrder()->getProductionStatus()->value,
            'tracking_code' => $item->getTrackingCode(),
            'release_version' => $release?->getVersionNumber(),
            'authorization_type' => $release?->getAuthorizationType()->value,
        ];
    }

    private function sameUser(User $left, User $right): bool
    {
        return $left === $right || ($left->getId() !== null && $left->getId() === $right->getId());
    }
}
