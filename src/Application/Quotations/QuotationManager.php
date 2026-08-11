<?php

namespace App\Application\Quotations;

use App\Application\Catalog\CommercialItemPriceResolver;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\ItemPriceRule;
use App\Entity\Quotations\Quotation;
use App\Entity\Quotations\QuotationEmailDispatch;
use App\Entity\Quotations\QuotationItem;
use App\Entity\Users\User;
use App\Enum\Quotations\QuotationStatus;
use App\Repository\Quotations\QuotationRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Quotations\QuotationFolioGenerator;
use App\Service\Quotations\QuotationMailer;
use Doctrine\DBAL\LockMode;

final class QuotationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CommercialItemPriceResolver $commercialItemPriceResolver,
        private readonly QuotationTotalsCalculator $totalsCalculator,
        private readonly AuditLogger $auditLogger,
        private readonly QuotationFolioGenerator $quotationFolioGenerator,
        private readonly QuotationMailer $quotationMailer,
        private readonly QuotationRepository $quotationRepository,
    ) {
    }

    public function create(QuotationData $data, User $actor): Quotation
    {
        return $this->entityManager->wrapInTransaction(
            function () use ($data, $actor): Quotation {
                $quotation = new Quotation();
                $quotation->setCreatedBy($actor);
                $this->applyData($quotation, $data);

                $this->entityManager->persist($quotation);
                $this->entityManager->flush();

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'quotation.created',
                    entityType: 'quotation',
                    entityId: $quotation->getId(),
                    newValues: $this->auditSnapshot($quotation),
                );

                $this->entityManager->flush();

                return $quotation;
            },
        );
    }

    public function update(
        Quotation $quotation,
        QuotationData $data,
        User $actor,
    ): void {
        if (!$quotation->isEditable()) {
            throw new \DomainException(
                'Solo las cotizaciones en borrador pueden modificarse.',
            );
        }

        $this->entityManager->wrapInTransaction(
            function () use ($quotation, $data, $actor): void {
                $oldValues = $this->auditSnapshot($quotation);

                $this->applyData($quotation, $data);

                $newValues = $this->auditSnapshot($quotation);

                if ($oldValues === $newValues) {
                    return;
                }

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'quotation.updated',
                    entityType: 'quotation',
                    entityId: $quotation->getId(),
                    oldValues: $oldValues,
                    newValues: $newValues,
                );

                $this->entityManager->flush();
            },
        );
    }

    public function issue(Quotation $quotation, User $actor): void
    {
        if ($quotation->getId() === null) {
            throw new \LogicException(
                'No es posible emitir una cotización sin identificar.',
            );
        }

        $this->entityManager->wrapInTransaction(
            function () use ($quotation, $actor): void {
                /*
                * Recarga y bloquea la cotización dentro de la transacción.
                * Así, dos solicitudes simultáneas no pueden emitir el mismo
                * borrador dos veces.
                */
                $this->entityManager->refresh(
                    $quotation,
                    LockMode::PESSIMISTIC_WRITE,
                );

                if (!$quotation->isEditable()) {
                    throw new \DomainException(
                        'Esta cotización ya fue emitida o dejó de ser editable.',
                    );
                }

                $oldValues = $this->auditSnapshot($quotation);

                $issuedAt = new \DateTimeImmutable(
                    'now',
                    new \DateTimeZone('UTC'),
                );

                $quotation->issue(
                    $this->quotationFolioGenerator->next($issuedAt),
                    $issuedAt,
                );

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'quotation.issued',
                    entityType: 'quotation',
                    entityId: $quotation->getId(),
                    oldValues: $oldValues,
                    newValues: $this->auditSnapshot($quotation),
                );

                $this->entityManager->flush();
            },
        );
    }

    public function send(Quotation $quotation, QuotationEmailData $data, User $actor): void
    {
        if ($quotation->getId() === null) {
            throw new \LogicException('No es posible enviar una cotización sin identificar.');
        }

        $this->entityManager->wrapInTransaction(
            function () use ($quotation, $data, $actor): void {
                $this->entityManager->refresh($quotation, LockMode::PESSIMISTIC_WRITE);
                $this->assertCanProcessCommercialResponse($quotation);

                if (!$quotation->getStatus()->canBeSent()) {
                    throw new \DomainException('Esta cotización no está disponible para enviarse por correo.');
                }

                $oldValues = $this->auditSnapshot($quotation);
                $messageId = $this->quotationMailer->send($quotation, $data);

                $dispatch = (new QuotationEmailDispatch($quotation, $actor))
                    ->setRecipientEmail((string) $data->recipientEmail)
                    ->setRecipientName($data->recipientName)
                    ->setCopyEmail($data->copyEmail)
                    ->setSubject(sprintf('Cotización %s | PrintFlow', $quotation->getFolio()))
                    ->setMessageNote($data->message)
                    ->setMessageId($messageId);

                $quotation->markSent();
                $this->entityManager->persist($dispatch);

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'quotation.sent',
                    entityType: 'quotation',
                    entityId: $quotation->getId(),
                    oldValues: $oldValues,
                    newValues: [
                        ...$this->auditSnapshot($quotation),
                        'email_dispatch' => [
                            'recipient_email' => $dispatch->getRecipientEmail(),
                            'recipient_name' => $dispatch->getRecipientName(),
                            'copy_email' => $dispatch->getCopyEmail(),
                            'subject' => $dispatch->getSubject(),
                            'message_id' => $dispatch->getMessageId(),
                            'sent_at' => $dispatch->getSentAt()->format(\DATE_ATOM),
                        ],
                    ],
                );

                $this->entityManager->flush();
            },
        );
    }

    public function accept(Quotation $quotation, QuotationDecisionData $data, User $actor): void
    {
        $this->recordDecision($quotation, QuotationStatus::ACCEPTED, $data, $actor);
    }

    public function reject(Quotation $quotation, QuotationDecisionData $data, User $actor): void
    {
        $this->recordDecision($quotation, QuotationStatus::REJECTED, $data, $actor);
    }

    public function cancel(Quotation $quotation, QuotationCancellationData $data, User $actor): void
    {
        if ($quotation->getId() === null) {
            throw new \LogicException('No es posible cancelar una cotización sin identificar.');
        }

        $this->entityManager->wrapInTransaction(
            function () use ($quotation, $data, $actor): void {
                $this->entityManager->refresh($quotation, LockMode::PESSIMISTIC_WRITE);
                $this->assertCanProcessCommercialResponse($quotation);

                $oldValues = $this->auditSnapshot($quotation);
                $quotation->cancel(
                    (string) $data->reason,
                    $this->now(),
                );

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'quotation.cancelled',
                    entityType: 'quotation',
                    entityId: $quotation->getId(),
                    oldValues: $oldValues,
                    newValues: $this->auditSnapshot($quotation),
                );
                $this->entityManager->flush();
            },
        );
    }

    public function createRevision(
        Quotation $quotation,
        QuotationRevisionData $data,
        User $actor,
    ): Quotation {
        if ($quotation->getId() === null) {
            throw new \LogicException('No es posible revisar una cotización sin identificar.');
        }

        return $this->entityManager->wrapInTransaction(
            function () use ($quotation, $data, $actor): Quotation {
                $this->entityManager->refresh($quotation, LockMode::PESSIMISTIC_WRITE);

                if (!$quotation->getStatus()->canBeRevised()) {
                    throw new \DomainException('Esta cotización no puede reemplazarse por una nueva revisión.');
                }

                if (!$quotation->getRevisions()->isEmpty()) {
                    throw new \DomainException('Esta cotización ya cuenta con una revisión posterior.');
                }

                $oldValues = $this->auditSnapshot($quotation);
                $revisionData = QuotationData::fromQuotation($quotation);
                $revisionData->expiresAt = new \DateTimeImmutable(
                    '+7 days',
                    new \DateTimeZone('America/Mexico_City'),
                );

                $revision = new Quotation();
                $revision
                    ->setPreviousRevision($quotation)
                    ->setRevisionNumber($quotation->getRevisionNumber() + 1);
                $this->applyData($revision, $revisionData);
                $revision->setCreatedBy($actor);

                $quotation->supersede((string) $data->reason, $this->now());
                $this->entityManager->persist($revision);
                $this->entityManager->flush();

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'quotation.superseded',
                    entityType: 'quotation',
                    entityId: $quotation->getId(),
                    oldValues: $oldValues,
                    newValues: [
                        ...$this->auditSnapshot($quotation),
                        'new_revision_id' => $revision->getId(),
                    ],
                );
                $this->auditLogger->record(
                    actor: $actor,
                    action: 'quotation.revision_created',
                    entityType: 'quotation',
                    entityId: $revision->getId(),
                    newValues: [
                        ...$this->auditSnapshot($revision),
                        'previous_revision_id' => $quotation->getId(),
                        'revision_reason' => $data->reason,
                    ],
                );
                $this->entityManager->flush();

                return $revision;
            },
        );
    }

    public function expireOverdue(): int
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('America/Mexico_City'));
        $expiredCount = 0;

        foreach ($this->quotationRepository->findExpirableBefore($today) as $quotation) {
            if ($this->expire($quotation)) {
                ++$expiredCount;
            }
        }

        return $expiredCount;
    }

    private function expire(Quotation $quotation): bool
    {
        if ($quotation->getId() === null) {
            return false;
        }

        return $this->entityManager->wrapInTransaction(
            function () use ($quotation): bool {
                $this->entityManager->refresh($quotation, LockMode::PESSIMISTIC_WRITE);

                if (!$quotation->getStatus()->canReceiveDecision() || !$this->isOverdue($quotation)) {
                    return false;
                }

                $oldValues = $this->auditSnapshot($quotation);
                $quotation->expire($this->now());

                $this->auditLogger->record(
                    actor: null,
                    action: 'quotation.expired',
                    entityType: 'quotation',
                    entityId: $quotation->getId(),
                    oldValues: $oldValues,
                    newValues: $this->auditSnapshot($quotation),
                );
                $this->entityManager->flush();

                return true;
            },
        );
    }

    private function recordDecision(
        Quotation $quotation,
        QuotationStatus $targetStatus,
        QuotationDecisionData $data,
        User $actor,
    ): void {
        if ($quotation->getId() === null) {
            throw new \LogicException('No es posible registrar una respuesta para una cotización sin identificar.');
        }

        $this->entityManager->wrapInTransaction(
            function () use ($quotation, $targetStatus, $data, $actor): void {
                $this->entityManager->refresh($quotation, LockMode::PESSIMISTIC_WRITE);
                $this->assertCanProcessCommercialResponse($quotation);

                if ($data->channel === null || $data->respondedAt === null) {
                    throw new \LogicException('Los datos de la respuesta comercial están incompletos.');
                }

                $oldValues = $this->auditSnapshot($quotation);
                if ($targetStatus === QuotationStatus::ACCEPTED) {
                    $quotation->accept(
                        $data->channel,
                        (string) $data->contact,
                        $data->respondedAt,
                        $data->notes,
                        $data->evidenceReference,
                    );
                } else {
                    $quotation->reject(
                        $data->channel,
                        (string) $data->contact,
                        $data->respondedAt,
                        $data->notes,
                        $data->evidenceReference,
                    );
                }

                $this->auditLogger->record(
                    actor: $actor,
                    action: $targetStatus === QuotationStatus::ACCEPTED
                        ? 'quotation.accepted'
                        : 'quotation.rejected',
                    entityType: 'quotation',
                    entityId: $quotation->getId(),
                    oldValues: $oldValues,
                    newValues: $this->auditSnapshot($quotation),
                );
                $this->entityManager->flush();
            },
        );
    }

    private function assertCanProcessCommercialResponse(Quotation $quotation): void
    {
        if (!$quotation->getStatus()->canReceiveDecision()) {
            throw new \DomainException('Esta cotización ya no admite acciones comerciales.');
        }

        if ($this->isOverdue($quotation)) {
            throw new \DomainException('La cotización venció. Ejecuta el proceso de expiración antes de continuar.');
        }
    }

    private function isOverdue(Quotation $quotation): bool
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('America/Mexico_City'));

        return $quotation->getExpiresAt()->format('Y-m-d') < $today->format('Y-m-d');
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function applyData(Quotation $quotation, QuotationData $data): void
    {
        if ($data->client === null || $data->expiresAt === null) {
            throw new \LogicException('Los datos de la cotización están incompletos.');
        }

        if (!$data->client->isActive()) {
            throw new \DomainException('No es posible cotizar para un cliente inactivo.');
        }

        $today = new \DateTimeImmutable(
            'today',
            new \DateTimeZone('America/Mexico_City'),
        );

        if ($data->expiresAt->format('Y-m-d') < $today->format('Y-m-d')) {
            throw new \DomainException(
                'La vigencia no puede ser una fecha anterior a hoy.',
            );
        }

        $discountPercent = $data->discountPercent;

        if ($discountPercent === null || trim($discountPercent) === '') {
            $discountPercent = number_format(
                $data->client->getDefaultDiscountPercent(),
                2,
                '.',
                '',
            );
        }

        $quotation
            ->setClient($data->client)
            ->setClientSnapshot($this->clientSnapshot($data->client))
            ->setExpiresAt($data->expiresAt)
            ->setNotes($data->notes)
            ->setDiscountPercent($discountPercent);

        /*
         * Los snapshots de dirección ya existen en la entidad y se llenarán
         * al integrar el selector de domicilio fiscal y de entrega.
         * No se modifican aquí para preservar la información existente.
         */

        $resolvedItems = [];

        foreach (array_values($data->items) as $index => $itemData) {
            if (!$itemData instanceof QuotationItemData
                || $itemData->commercialItem === null
                || $itemData->quantity === null
            ) {
                throw new \LogicException(
                    'Los datos de una partida están incompletos.',
                );
            }

            $commercialItem = $itemData->commercialItem;

            if (!$commercialItem->isActive()) {
                throw new \DomainException(
                    sprintf(
                        'El concepto "%s" está inactivo y no puede cotizarse.',
                        $commercialItem->getName(),
                    ),
                );
            }

            $quantity = ItemPriceRule::normalizeMinimumQuantity($itemData->quantity);

            $resolution = $this->commercialItemPriceResolver->resolve(
                $commercialItem,
                $quantity,
            );

            $unitPrice = Quotation::normalizeAmount(
                $resolution->unitPrice,
                'El precio unitario resuelto',
            );

            $lineSubtotal = $this->totalsCalculator->lineSubtotal(
                $resolution->quantity,
                $unitPrice,
            );

            $resolvedItems[] = [
                'line_number' => $index + 1,
                'commercial_item' => $commercialItem,
                'quantity' => $resolution->quantity,
                'unit_price' => $unitPrice,
                'line_subtotal' => $lineSubtotal,
                'commercial_item_snapshot' => $this->commercialItemSnapshot(
                    $commercialItem,
                ),
                'price_rule_snapshot' => $this->priceRuleSnapshot(
                    $resolution->appliedRule,
                    $unitPrice,
                ),
            ];
        }

        $totals = $this->totalsCalculator->calculate(
            array_column($resolvedItems, 'line_subtotal'),
            $quotation->getDiscountPercent(),
            $quotation->getTaxRate(),
        );

        $currentItems = array_values($quotation->getItems()->toArray());

        foreach ($resolvedItems as $index => $resolvedItem) {
            $quotationItem = $currentItems[$index] ?? new QuotationItem();

            $quotationItem
                ->setLineNumber($resolvedItem['line_number'])
                ->setCommercialItem($resolvedItem['commercial_item'])
                ->setQuantity($resolvedItem['quantity'])
                ->setUnitPrice($resolvedItem['unit_price'])
                ->setLineSubtotal($resolvedItem['line_subtotal'])
                ->setCommercialItemSnapshot(
                    $resolvedItem['commercial_item_snapshot'],
                )
                ->setPriceRuleSnapshot($resolvedItem['price_rule_snapshot']);

            if (!isset($currentItems[$index])) {
                $quotation->addItem($quotationItem);
            }
        }

        for ($index = count($resolvedItems); $index < count($currentItems); ++$index) {
            $quotation->removeItem($currentItems[$index]);
        }

        $quotation->setTotals(
            subtotal: $totals->subtotal,
            discountAmount: $totals->discountAmount,
            taxableAmount: $totals->taxableAmount,
            taxAmount: $totals->taxAmount,
            total: $totals->total,
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    private function clientSnapshot(object $client): array
    {
        /** @var \App\Entity\Clients\Client $client */
        return [
            'client_id' => $client->getId(),
            'business_name' => $client->getBusinessName(),
            'legal_name' => $client->getLegalName(),
            'tax_id' => $client->getTaxId(),
            'tax_regime_code' => $client->getTaxRegimeCode(),
            'fiscal_postal_code' => $client->getFiscalPostalCode(),
            'billing_email' => $client->getBillingEmail(),
            'default_cfdi_use_code' => $client->getDefaultCfdiUseCode(),
            'email' => $client->getEmail(),
            'phone' => $client->getPhone(),
        ];
    }

    /**
     * @return array<string, array<int|string, int|string>|int|string|null>
     */
    private function commercialItemSnapshot(CommercialItem $item): array
    {
        return [
            'commercial_item_id' => $item->getId(),
            'code' => $item->getCode(),
            'name' => $item->getName(),
            'description' => $item->getDescription(),
            'type' => $item->getType()->value,
            'category' => [
                'id' => $item->getCategory()->getId(),
                'code' => $item->getCategory()->getCode(),
                'name' => $item->getCategory()->getName(),
            ],
            'measurement_unit' => [
                'id' => $item->getMeasurementUnit()->getId(),
                'code' => $item->getMeasurementUnit()->getCode(),
                'name' => $item->getMeasurementUnit()->getName(),
            ],
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function priceRuleSnapshot(
        ?ItemPriceRule $rule,
        string $resolvedUnitPrice,
    ): array {
        return [
            'price_source' => $rule === null ? 'BASE_PRICE' : 'QUANTITY_TIER',
            'resolved_unit_price' => $resolvedUnitPrice,
            'item_price_rule_id' => $rule?->getId(),
            'rule_type' => $rule?->getRuleType()->value,
            'min_quantity' => $rule?->getMinQuantity(),
            'rule_unit_price' => $rule?->getUnitPrice(),
        ];
    }

    /**
     * @return array<string, array<int|string, array<string, int|string|null>>|int|string|null>
     */
    private function auditSnapshot(Quotation $quotation): array
    {
        $items = [];

        foreach ($quotation->getItems() as $item) {
            $itemSnapshot = $item->getCommercialItemSnapshot();

            $items[] = [
                'line_number' => $item->getLineNumber(),
                'commercial_item_id' => $item->getCommercialItem()->getId(),
                'commercial_item_code' => $itemSnapshot['code'] ?? null,
                'commercial_item_name' => $itemSnapshot['name'] ?? null,
                'quantity' => $item->getQuantity(),
                'unit_price' => $item->getUnitPrice(),
                'line_subtotal' => $item->getLineSubtotal(),
            ];
        }

        return [
            'folio' => $quotation->getFolio(),
            'status' => $quotation->getStatus()->value,
            'issued_at' => $quotation->getIssuedAt()?->format(\DATE_ATOM),
            'revision_number' => $quotation->getRevisionNumber(),
            'previous_revision_id' => $quotation->getPreviousRevision()?->getId(),
            'decision_channel' => $quotation->getDecisionChannel()?->value,
            'decision_contact' => $quotation->getDecisionContact(),
            'decision_at' => $quotation->getDecisionAt()?->format(\DATE_ATOM),
            'decision_notes' => $quotation->getDecisionNotes(),
            'decision_evidence_reference' => $quotation->getDecisionEvidenceReference(),
            'client_id' => $quotation->getClient()->getId(),
            'client_name' => $quotation->getClientSnapshot()['business_name'] ?? null,
            'expires_at' => $quotation->getExpiresAt()->format('Y-m-d'),
            'discount_percent' => $quotation->getDiscountPercent(),
            'tax_rate' => $quotation->getTaxRate(),
            'subtotal' => $quotation->getSubtotal(),
            'discount_amount' => $quotation->getDiscountAmount(),
            'taxable_amount' => $quotation->getTaxableAmount(),
            'tax_amount' => $quotation->getTaxAmount(),
            'total' => $quotation->getTotal(),
            'items' => $items,
        ];
    }
}
