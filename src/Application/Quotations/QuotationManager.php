<?php

namespace App\Application\Quotations;

use App\Application\Catalog\CommercialItemPriceResolver;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\ItemPriceRule;
use App\Entity\Clients\Client;
use App\Entity\Clients\ClientAddress;
use App\Entity\Clients\ClientContact;
use App\Entity\Quotations\Quotation;
use App\Entity\Quotations\QuotationEmailDispatch;
use App\Entity\Quotations\QuotationItem;
use App\Entity\Users\User;
use App\Enum\Quotations\QuotationStatus;
use App\Repository\Catalog\CommercialItemRepository;
use App\Repository\Clients\ClientAddressRepository;
use App\Repository\Clients\ClientContactRepository;
use App\Repository\Clients\ClientRepository;
use App\Repository\Quotations\QuotationRepository;
use App\Service\Audit\AuditLogger;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
        private readonly ClientRepository $clientRepository,
        private readonly ClientContactRepository $clientContactRepository,
        private readonly ClientAddressRepository $clientAddressRepository,
        private readonly CommercialItemRepository $commercialItemRepository,
        private readonly QuotationItemSpecificationResolver $specificationResolver,
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

    public function createPublic(QuotationData $data, PublicQuotationRequestData $request): Quotation
    {
        return $this->entityManager->wrapInTransaction(function () use ($data, $request): Quotation {
            $quotation = new Quotation();
            $this->applyData($quotation, $data);
            $quotation->initializePublicRequest(
                sprintf('SOL-%s-%s', (new \DateTimeImmutable())->format('Ymd'), strtoupper(bin2hex(random_bytes(3)))),
                (string) $request->fullName,
                (string) $request->email,
                (string) $request->phone,
                $request->companyName,
                $request->contactPreference,
                $request->deliveryMethod,
                $request->neededAt,
                $request->requiresInvoice,
            );
            foreach (array_values($quotation->getItems()->toArray()) as $index => $quotationItem) {
                $source = $request->items[$index] ?? null;
                if (!$source instanceof PublicQuotationRequestItemData) { continue; }
                $quotationItem->setRequestDetails($source->requestDetails())->setAttachmentPath($source->attachmentPath)->setAttachmentOriginalName($source->attachmentOriginalName);
            }
            $this->entityManager->persist($quotation);
            $this->entityManager->flush();
            return $quotation;
        });
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

    public function startReview(Quotation $quotation, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($quotation, $actor): void {$quotation->startReview();$this->auditLogger->record(actor:$actor,action:'quotation.review_started',entityType:'quotation',entityId:$quotation->getId(),newValues:$this->auditSnapshot($quotation));$this->entityManager->flush();});
    }

    public function prepareDraft(Quotation $quotation, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($quotation, $actor): void {$quotation->prepareDraft();$this->auditLogger->record(actor:$actor,action:'quotation.draft_prepared',entityType:'quotation',entityId:$quotation->getId(),newValues:$this->auditSnapshot($quotation));$this->entityManager->flush();});
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

        $client = $this->resolveActiveClient($data->client);

        $today = new \DateTimeImmutable(
            'today',
            new \DateTimeZone('America/Mexico_City'),
        );

        if ($data->expiresAt->format('Y-m-d') < $today->format('Y-m-d')) {
            throw new \DomainException(
                'La vigencia no puede ser una fecha anterior a hoy.',
            );
        }

        $discountPercent = $this->resolveDiscountPercent(
            $data->discountPercent,
            $client,
        );
        $commercialContact = $this->resolveCommercialContact(
            $data->commercialContactId,
            $client,
        );
        $fiscalAddress = $this->resolveClientAddress(
            $data->fiscalAddressId,
            $client,
            'FISCAL',
            'fiscal',
        );
        $deliveryAddress = $this->resolveClientAddress(
            $data->deliveryAddressId,
            $client,
            'DELIVERY',
            'de entrega',
        );

        $quotation
            ->setClient($client)
            ->setClientSnapshot($this->clientSnapshot($client, $commercialContact))
            ->setFiscalAddressSnapshot($this->clientAddressSnapshot($fiscalAddress))
            ->setDeliveryAddressSnapshot($this->clientAddressSnapshot($deliveryAddress))
            ->setExpiresAt($data->expiresAt)
            ->setNotes($data->notes)
            ->setDiscountPercent($discountPercent);

        $resolvedItems = [];

        foreach (array_values($data->items) as $index => $itemData) {
            if (!$itemData instanceof QuotationItemData
                || $itemData->commercialItem === null
                || $itemData->commercialCategory === null
                || $itemData->quantity === null
            ) {
                throw new \LogicException(
                    'Los datos de una partida están incompletos.',
                );
            }

            $commercialItem = $this->resolveActiveCommercialItem(
                $itemData->commercialItem,
            );

            if ($itemData->commercialCategory->getId() === null
                || $commercialItem->getCategory()->getId() !== $itemData->commercialCategory->getId()) {
                throw new \DomainException(
                    'El Producto seleccionado no pertenece a la categoría indicada.',
                );
            }

            $specification = $this->specificationResolver->resolve(
                $commercialItem,
                $itemData->specifications,
                $itemData->quantity,
                $itemData->quantityMode,
            );
            $quantity = $specification['quantity'];

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
                'specifications_snapshot' => $specification['snapshot'],
                'specification_schema_version' => $specification['schema_version'],
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
                ->setPriceRuleSnapshot($resolvedItem['price_rule_snapshot'])
                ->setSpecificationsSnapshot($resolvedItem['specifications_snapshot'])
                ->setSpecificationSchemaVersion($resolvedItem['specification_schema_version']);

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

    private function resolveActiveClient(?\App\Entity\Clients\Client $selectedClient): \App\Entity\Clients\Client
    {
        $clientId = $selectedClient?->getId();

        if ($clientId === null) {
            throw new \LogicException('Los datos de la cotización están incompletos.');
        }

        $client = $this->clientRepository->findActiveForQuotation($clientId);

        if ($client === null) {
            throw new \DomainException('No es posible cotizar para un cliente inactivo o inexistente.');
        }

        return $client;
    }

    private function resolveActiveCommercialItem(?CommercialItem $selectedItem): CommercialItem
    {
        $itemId = $selectedItem?->getId();

        if ($itemId === null) {
            throw new \LogicException('Los datos de una partida están incompletos.');
        }

        $item = $this->commercialItemRepository->findActiveForQuotation($itemId);

        if ($item === null) {
            throw new \DomainException('El concepto seleccionado está inactivo o ya no existe.');
        }

        return $item;
    }

    private function resolveCommercialContact(
        ?string $selectedContactId,
        Client $client,
    ): ?ClientContact {
        $contactId = $this->optionalSelectionId(
            $selectedContactId,
            'contacto comercial',
        );

        if ($contactId === null) {
            return null;
        }

        $contact = $this->clientContactRepository->findActiveForQuotation(
            $contactId,
            $client,
        );

        if ($contact === null) {
            throw new \DomainException(
                'El contacto comercial seleccionado no está activo o no pertenece al cliente.',
            );
        }

        return $contact;
    }

    private function resolveClientAddress(
        ?string $selectedAddressId,
        Client $client,
        string $addressType,
        string $label,
    ): ?ClientAddress {
        $addressId = $this->optionalSelectionId(
            $selectedAddressId,
            'domicilio '.$label,
        );

        if ($addressId === null) {
            return null;
        }

        $address = $this->clientAddressRepository->findActiveForQuotation(
            $addressId,
            $client,
            $addressType,
        );

        if ($address === null) {
            throw new \DomainException(sprintf(
                'El domicilio %s seleccionado no está activo o no pertenece al cliente.',
                $label,
            ));
        }

        return $address;
    }

    private function optionalSelectionId(?string $rawId, string $label): ?int
    {
        $rawId = trim((string) $rawId);
        if ($rawId === '') {
            return null;
        }

        if (preg_match('/^[1-9]\d*$/', $rawId) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'El %s seleccionado no es válido.',
                $label,
            ));
        }

        return (int) $rawId;
    }

    private function resolveDiscountPercent(
        ?string $submittedDiscountPercent,
        \App\Entity\Clients\Client $client,
    ): string {
        $usesClientDefault = $submittedDiscountPercent === null
            || trim($submittedDiscountPercent) === '';

        $rawPercent = $usesClientDefault
            ? (string) $client->getDefaultDiscountPercent()
            : trim($submittedDiscountPercent);

        $rawPercent = str_replace(',', '.', $rawPercent);

        $pattern = $usesClientDefault
            ? '/^\d{1,3}(?:\.\d+)?$/'
            : '/^\d{1,3}(?:\.\d{1,2})?$/';

        if (!preg_match($pattern, $rawPercent)) {
            throw new \InvalidArgumentException(
                'El descuento global debe ser un porcentaje entre 0 y 100 con máximo dos decimales.',
            );
        }

        $discountPercent = BigDecimal::of($rawPercent);

        if ($discountPercent->compareTo('0') < 0 || $discountPercent->compareTo('100') > 0) {
            throw new \InvalidArgumentException(
                'El descuento global debe estar entre 0 y 100.',
            );
        }

        return $discountPercent
            ->toScale(2, RoundingMode::HalfUp)
            ->__toString();
    }

    /** @return array<string, mixed> */
    private function clientSnapshot(
        Client $client,
        ?ClientContact $commercialContact,
    ): array
    {
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
            'commercial_contact' => $commercialContact === null ? null : [
                'client_contact_id' => $commercialContact->getId(),
                'full_name' => $commercialContact->getFullName(),
                'department' => $commercialContact->getDepartment(),
                'job_title' => $commercialContact->getJobTitle(),
                'email' => $commercialContact->getEmail(),
                'phone' => $commercialContact->getPhone(),
            ],
        ];
    }

    /** @return array<string, int|string|null>|null */
    private function clientAddressSnapshot(?ClientAddress $address): ?array
    {
        if ($address === null) {
            return null;
        }

        $street = trim($address->getStreet().' '.$address->getExteriorNumber());
        if ($address->getInteriorNumber() !== null) {
            $street .= ' Int. '.$address->getInteriorNumber();
        }

        return [
            'client_address_id' => $address->getId(),
            'label' => $address->getLabel(),
            'recipient_name' => $address->getRecipientName(),
            'address_type' => $address->getAddressType(),
            'street' => $address->getStreet(),
            'exterior_number' => $address->getExteriorNumber(),
            'interior_number' => $address->getInteriorNumber(),
            'neighborhood' => $address->getNeighborhood(),
            'postal_code' => $address->getPostalCode(),
            'city' => $address->getMunicipality(),
            'state' => $address->getState(),
            'country_code' => $address->getCountryCode(),
            'references' => $address->getReferences(),
            'formatted_address' => implode(', ', array_filter([
                $street,
                $address->getNeighborhood(),
                $address->getMunicipality(),
                $address->getState(),
                'CP '.$address->getPostalCode(),
            ])),
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
            'quotation_specification_profile' => $item->getQuotationSpecificationProfile()->value,
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
                'specifications' => $item->getSpecificationsSnapshot(),
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
            'commercial_contact' => $quotation->getClientSnapshot()['commercial_contact'] ?? null,
            'fiscal_address' => $quotation->getFiscalAddressSnapshot(),
            'delivery_address' => $quotation->getDeliveryAddressSnapshot(),
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
