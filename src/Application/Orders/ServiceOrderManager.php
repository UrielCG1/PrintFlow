<?php

declare(strict_types=1);

namespace App\Application\Orders;

use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Quotations\Quotation;
use App\Entity\Quotations\QuotationItem;
use App\Entity\Users\User;
use App\Enum\Quotations\QuotationStatus;
use App\Repository\Orders\ServiceOrderRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Orders\ServiceOrderFolioGenerator;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class ServiceOrderManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceOrderRepository $serviceOrderRepository,
        private readonly ServiceOrderFolioGenerator $folioGenerator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function createFromAcceptedQuotation(Quotation $quotation, User $actor): ServiceOrder
    {
        if ($quotation->getId() === null) {
            throw new \LogicException('No es posible crear una orden desde una cotización sin identificar.');
        }

        return $this->entityManager->wrapInTransaction(
            function () use ($quotation, $actor): ServiceOrder {
                /*
                 * La cotización se bloquea, no la pantalla. Así se impide que
                 * dos solicitudes creen dos órdenes a partir del mismo origen.
                 */
                $this->entityManager->refresh($quotation, LockMode::PESSIMISTIC_WRITE);

                if ($quotation->getStatus() !== QuotationStatus::ACCEPTED) {
                    throw new \DomainException('Solo una cotización aceptada puede convertirse en una orden de servicio.');
                }

                if (!$quotation->hasBeenIssued() || $quotation->getFolio() === null) {
                    throw new \DomainException('La cotización aceptada debe contar con un folio emitido.');
                }

                if ($this->serviceOrderRepository->findOneBySourceQuotation($quotation) !== null) {
                    throw new \DomainException('Esta cotización ya tiene una orden de servicio asociada.');
                }

                if ($quotation->getItems()->isEmpty()) {
                    throw new \DomainException('La cotización aceptada no tiene partidas para convertir en una orden.');
                }

                $createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $serviceOrder = (new ServiceOrder())
                    ->setSourceQuotation($quotation)
                    ->setCreatedBy($actor)
                    ->setFolio($this->folioGenerator->next($createdAt))
                    ->setSourceQuotationFolio($quotation->getFolio())
                    ->setQuotationSnapshot($this->quotationSnapshot($quotation))
                    ->setClientSnapshot($quotation->getClientSnapshot())
                    ->setFiscalAddressSnapshot($quotation->getFiscalAddressSnapshot())
                    ->setDeliveryAddressSnapshot($quotation->getDeliveryAddressSnapshot())
                    ->setNotes($quotation->getNotes())
                    ->setCurrency($quotation->getCurrency())
                    ->setDiscountPercent($quotation->getDiscountPercent())
                    ->setTaxRate($quotation->getTaxRate())
                    ->setTotals(
                        $quotation->getSubtotal(),
                        $quotation->getDiscountAmount(),
                        $quotation->getTaxableAmount(),
                        $quotation->getTaxAmount(),
                        $quotation->getTotal(),
                    );

                foreach ($quotation->getItems() as $quotationItem) {
                    $serviceOrder->addItem($this->copyItem($quotationItem));
                }

                $this->entityManager->persist($serviceOrder);
                $this->entityManager->flush();

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'service_order.created_from_quotation',
                    entityType: 'service_order',
                    entityId: $serviceOrder->getId(),
                    newValues: $this->auditSnapshot($serviceOrder),
                );
                $this->entityManager->flush();

                return $serviceOrder;
            },
        );
    }

    private function copyItem(QuotationItem $quotationItem): ServiceOrderItem
    {
        return (new ServiceOrderItem())
            ->setSourceQuotationItem($quotationItem)
            ->setCommercialItem($quotationItem->getCommercialItem())
            ->setLineNumber($quotationItem->getLineNumber())
            ->setQuantity($quotationItem->getQuantity())
            ->setUnitPrice($quotationItem->getUnitPrice())
            ->setLineSubtotal($quotationItem->getLineSubtotal())
            ->setCommercialItemSnapshot($quotationItem->getCommercialItemSnapshot())
            ->setPriceRuleSnapshot($quotationItem->getPriceRuleSnapshot());
    }

    /** @return array<string, int|string|null> */
    private function quotationSnapshot(Quotation $quotation): array
    {
        return [
            'quotation_id' => $quotation->getId(),
            'folio' => $quotation->getFolio(),
            'revision_number' => $quotation->getRevisionNumber(),
            'issued_at' => $quotation->getIssuedAt()?->format(\DATE_ATOM),
            'accepted_at' => $quotation->getDecisionAt()?->format(\DATE_ATOM),
            'acceptance_channel' => $quotation->getDecisionChannel()?->value,
            'acceptance_contact' => $quotation->getDecisionContact(),
            'acceptance_notes' => $quotation->getDecisionNotes(),
            'acceptance_evidence_reference' => $quotation->getDecisionEvidenceReference(),
        ];
    }

    /** @return array<string, int|string|null> */
    private function auditSnapshot(ServiceOrder $serviceOrder): array
    {
        return [
            'folio' => $serviceOrder->getFolio(),
            'status' => $serviceOrder->getStatus()->value,
            'source_quotation_id' => $serviceOrder->getSourceQuotation()->getId(),
            'source_quotation_folio' => $serviceOrder->getSourceQuotationFolio(),
            'client_name' => $serviceOrder->getClientSnapshot()['business_name'] ?? null,
            'total' => $serviceOrder->getTotal(),
            'currency' => $serviceOrder->getCurrency(),
            'commitment_date' => $serviceOrder->getCommitmentDate()?->format('Y-m-d'),
            'items' => $serviceOrder->getItems()->count(),
        ];
    }
}
