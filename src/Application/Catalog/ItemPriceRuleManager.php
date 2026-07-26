<?php

namespace App\Application\Catalog;

use App\Entity\Catalog\ItemPriceRule;
use App\Entity\Users\User;
use App\Service\Audit\AuditLogger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class ItemPriceRuleManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(ItemPriceRuleData $data, User $actor): ItemPriceRule
    {
        try {
            return $this->entityManager->wrapInTransaction(
                function () use ($data, $actor): ItemPriceRule {
                    $rule = new ItemPriceRule();
                    $this->applyData($rule, $data);

                    $this->entityManager->persist($rule);
                    $this->entityManager->flush();

                    $this->auditLogger->record(
                        $actor,
                        'item_price_rule.created',
                        'item_price_rule',
                        $rule->getId(),
                        null,
                        $this->snapshot($rule),
                    );

                    $this->entityManager->flush();

                    return $rule;
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new \DomainException(
                'Ya existe un rango con esta cantidad mínima para el concepto.',
                0,
                $exception,
            );
        }
    }

    public function update(
        ItemPriceRule $rule,
        ItemPriceRuleData $data,
        User $actor,
    ): void {
        try {
            $this->entityManager->wrapInTransaction(
                function () use ($rule, $data, $actor): void {
                    $oldValues = $this->snapshot($rule);

                    $this->applyData($rule, $data);

                    $newValues = $this->snapshot($rule);

                    if ($oldValues === $newValues) {
                        return;
                    }

                    $action = $oldValues['unit_price'] !== $newValues['unit_price']
                        ? 'item_price_rule.price_updated'
                        : 'item_price_rule.updated';

                    $this->auditLogger->record(
                        $actor,
                        $action,
                        'item_price_rule',
                        $rule->getId(),
                        $oldValues,
                        $newValues,
                    );

                    $this->entityManager->flush();
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new \DomainException(
                'Ya existe un rango con esta cantidad mínima para el concepto.',
                0,
                $exception,
            );
        }
    }

    public function setActive(ItemPriceRule $rule, bool $isActive, User $actor): void
    {
        if ($rule->isActive() === $isActive) {
            return;
        }

        $this->entityManager->wrapInTransaction(
            function () use ($rule, $isActive, $actor): void {
                $oldValues = $this->snapshot($rule);

                $rule->setIsActive($isActive);

                $this->auditLogger->record(
                    $actor,
                    $isActive
                        ? 'item_price_rule.activated'
                        : 'item_price_rule.deactivated',
                    'item_price_rule',
                    $rule->getId(),
                    $oldValues,
                    $this->snapshot($rule),
                );

                $this->entityManager->flush();
            },
        );
    }

    private function applyData(ItemPriceRule $rule, ItemPriceRuleData $data): void
    {
        if ($data->commercialItem === null || $data->ruleType === null) {
            throw new \LogicException('Los datos de la regla de precio están incompletos.');
        }

        $rule
            ->setCommercialItem($data->commercialItem)
            ->setRuleType($data->ruleType)
            ->setMinQuantity((string) $data->minQuantity)
            ->setUnitPrice((string) $data->unitPrice);
    }

    /** @return array<string, bool|string> */
    private function snapshot(ItemPriceRule $rule): array
    {
        $item = $rule->getCommercialItem();

        return [
            'commercial_item' => $item->getCode().' — '.$item->getName(),
            'rule_type' => $rule->getRuleType()->label(),
            'min_quantity' => $rule->getMinQuantity(),
            'unit_price' => $rule->getUnitPrice(),
            'is_active' => $rule->isActive(),
        ];
    }
}