<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryPurchaseRequest;
use App\Entity\Inventory\InventoryPurchaseRequestLine;
use App\Entity\Users\User;
use App\Repository\Inventory\InventoryPurchaseRequestLineRepository;
use App\Repository\Inventory\InventoryPurchaseRequestRepository;
use App\Service\Audit\AuditLogger;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Creates and starts internal purchase tracking without posting inventory. */
final class InventoryPurchaseRequestManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InventoryPurchaseRequestRepository $requestRepository,
        private readonly InventoryPurchaseRequestLineRepository $lineRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(InventoryPurchaseRequestCommand $command, User $actor): InventoryPurchaseRequest
    {
        if ($command->lines === []) {
            throw new \InvalidArgumentException('La solicitud de compra debe incluir al menos un material.');
        }
        $fingerprint = $this->fingerprint($command);

        return $this->entityManager->wrapInTransaction(function () use ($command, $actor, $fingerprint): InventoryPurchaseRequest {
            $existing = $this->requestRepository->findOneByIdempotencyKey($command->idempotencyKey);
            if ($existing instanceof InventoryPurchaseRequest) {
                $this->assertSameCommand($existing, $fingerprint);

                return $existing;
            }

            $lines = $this->sortedLines($command->lines);
            foreach ($lines as $line) {
                $this->entityManager->lock($line->variant, LockMode::PESSIMISTIC_WRITE);
                $openLine = $this->lineRepository->findOpenForVariant($line->variant);
                if ($openLine instanceof InventoryPurchaseRequestLine) {
                    throw new \DomainException('La presentación '.$line->variant->getCode().' ya tiene la solicitud '.$openLine->getPurchaseRequest()->getRequestCode().' pendiente o en seguimiento.');
                }
                if ($line->supplierOffer !== null && $line->supplierOffer->getVariant() !== $line->variant) {
                    throw new \DomainException('La oferta seleccionada no corresponde al material solicitado.');
                }
            }

            $existing = $this->requestRepository->findOneByIdempotencyKey($command->idempotencyKey);
            if ($existing instanceof InventoryPurchaseRequest) {
                $this->assertSameCommand($existing, $fingerprint);

                return $existing;
            }

            $request = new InventoryPurchaseRequest($command->idempotencyKey, $fingerprint, $actor, $command->notes);
            $this->entityManager->persist($request);
            foreach ($lines as $position => $input) {
                $line = new InventoryPurchaseRequestLine($request, $position + 1, $input->variant, $input->quantity, $input->supplierOffer);
                $request->addLine($line);
                $this->entityManager->persist($line);
            }
            $this->entityManager->flush();

            $this->auditLogger->record($actor, 'inventory.purchase_request.created', 'inventory_purchase_request', $request->getId(), null, [
                'request_code' => $request->getRequestCode(),
                'line_count' => count($lines),
            ]);
            $this->entityManager->flush();

            return $request;
        });
    }

    public function beginTracking(InventoryPurchaseRequest $submittedRequest, ?string $trackingReference, User $actor): InventoryPurchaseRequest
    {
        $id = $submittedRequest->getId();
        if ($id === null) {
            throw new \InvalidArgumentException('La solicitud de compra indicada no está registrada.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($id, $trackingReference, $actor): InventoryPurchaseRequest {
            $request = $this->requestRepository->findForUpdate($id);
            if (!$request instanceof InventoryPurchaseRequest) {
                throw new \DomainException('La solicitud de compra indicada ya no existe.');
            }
            $request->beginTracking($trackingReference);
            $this->auditLogger->record($actor, 'inventory.purchase_request.tracking_started', 'inventory_purchase_request', $request->getId(), [
                'status' => 'DRAFT',
            ], [
                'status' => $request->getStatus()->value,
                'tracking_reference' => $request->getTrackingReference(),
            ]);
            $this->entityManager->flush();

            return $request;
        });
    }

    private function assertSameCommand(InventoryPurchaseRequest $request, string $fingerprint): void
    {
        if ($request->getCommandFingerprint() !== $fingerprint) {
            throw new \DomainException('La clave de idempotencia ya se utilizó para una solicitud de compra con datos distintos.');
        }
    }

    /** @param list<InventoryPurchaseRequestLineInput> $lines @return list<InventoryPurchaseRequestLineInput> */
    private function sortedLines(array $lines): array
    {
        foreach ($lines as $line) {
            if (!$line instanceof InventoryPurchaseRequestLineInput || $line->variant->getId() === null) {
                throw new \InvalidArgumentException('Cada partida debe indicar una presentación de material ya registrada.');
            }
        }
        usort($lines, static fn (InventoryPurchaseRequestLineInput $left, InventoryPurchaseRequestLineInput $right): int => $left->variant->getId() <=> $right->variant->getId());
        foreach ($lines as $index => $line) {
            if ($index > 0 && $lines[$index - 1]->variant->getId() === $line->variant->getId()) {
                throw new \InvalidArgumentException('No repitas la misma presentación en una solicitud de compra.');
            }
        }

        return $lines;
    }

    private function fingerprint(InventoryPurchaseRequestCommand $command): string
    {
        $lines = [];
        foreach ($command->lines as $line) {
            if (!$line instanceof InventoryPurchaseRequestLineInput || $line->variant->getId() === null) {
                throw new \InvalidArgumentException('Cada partida debe indicar una presentación de material ya registrada.');
            }
            $quantity = trim(str_replace(',', '.', $line->quantity));
            if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $quantity) !== 1 || BigDecimal::of($quantity)->isLessThanOrEqualTo(BigDecimal::zero())) {
                throw new \InvalidArgumentException('La cantidad solicitada debe ser positiva y tener máximo seis decimales.');
            }
            $lines[] = [
                'offer' => $line->supplierOffer?->getId(),
                'quantity' => BigDecimal::of($quantity)->toScale(6)->__toString(),
                'variant' => $line->variant->getId(),
            ];
        }
        usort($lines, static fn (array $left, array $right): int => $left['variant'] <=> $right['variant']);
        $notes = trim((string) $command->notes);
        if (mb_strlen($notes) > 4000) {
            throw new \InvalidArgumentException('Las notas de la solicitud no pueden superar 4000 caracteres.');
        }

        return hash('sha256', json_encode(['lines' => $lines, 'notes' => $notes], JSON_THROW_ON_ERROR));
    }
}
