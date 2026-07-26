<?php

namespace App\Application\Quotations;

use App\Application\Catalog\CommercialItemPriceResolver;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\ItemPriceRule;
use App\Entity\Quotations\Quotation;
use App\Entity\Quotations\QuotationItem;
use App\Entity\Users\User;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Quotations\QuotationFolioGenerator;
use Doctrine\DBAL\LockMode;

final class QuotationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CommercialItemPriceResolver $commercialItemPriceResolver,
        private readonly QuotationTotalsCalculator $totalsCalculator,
        private readonly AuditLogger $auditLogger,
        private readonly QuotationFolioGenerator $quotationFolioGenerator,
    ) {
    }

    public function create(QuotationData $data, User $actor): Quotation
    {
        return $this->entityManager->wrapInTransaction(
            function () use ($data, $actor): Quotation {
                $quotation = new Quotation();

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